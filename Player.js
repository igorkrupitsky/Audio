let basePath = '';
let sFolderSep = '\\';
let sCurrentFolder = "";
let currentData = null;
let gainNode = null;
let audioCtx = null;
let bAudioGaininitialized = false;

// Volume: 0-200% slider (above 100% is boost) plus "Speaker mode" voice processing.
// Anything past 100%, or Speaker mode, runs through Web Audio; see initializeAudioGain().
const VOLUME_MAX = 200;
const VOLUME_SNAP = 6;           // snap to 100% when this close to it
let volumePct = 100;
let speakerMode = false;
let voiceNodes = null;           // { hp, presence, comp, boost, limiter, master } once Web Audio is on

// Track current fave state for current folder
let currentIsFave = false;

// Global flag to track dialog source
let editDialogOpenedFromTable = false;

// Player state
let playerInitialized = false;   // listeners/timers registered once (see initPlayerOnce)
let playingFolderPath = '';      // folder the loaded audio belongs to (progress is saved here)
let pendingResumeFolder = null;  // set while a folder's saved position is still loading
let lastSavedProgressKey = '';
let trackLoadSeq = 0;            // ignore stale resume lookups after navigating away
let audioLoadToken = 0;          // ignore stale setAudioFile calls
let currentObjectUrl = null;     // blob URL of a cached track, revoked on the next switch
let folderRequestSeq = 0;        // ignore stale folder listings (out-of-order responses)

function getBasePathAndStart() {

    // if ('serviceWorker' in navigator) {
    //     navigator.serviceWorker.register('service-worker.js').then(() => {
    //         console.log('Service Worker registered');
    //     }).catch(console.error);
    // }

    fetch('?mode=basepath')
        .then(response => {
            if (!response.ok) throw new Error('Unable to retrieve base path.');
            return response.json();
        })
        .then(data => {
            basePath = data.basePath;
            if (data.OS && data.OS != 'Windows') sFolderSep = "/";
            const params = new URLSearchParams(window.location.search);
            const folder_param = params.get('folder');
            resolveInitialFolder(folder_param).then(path => loadFolder(path));
        })
        .catch(err => showError(err.message));

    // Volume panel: slider + Speaker mode.
    initVolumeControls();

    // After unlocking the phone, wake the boost audio back up if it was used.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') resumeAudioContext();
    });

}

document.addEventListener('DOMContentLoaded', function () {
    initPlayerOnce();
    getBasePathAndStart();
    setupFaveStar();
});

let toastTimer = null;
function showToast(text, ms = 1800) {
    const o = document.querySelector('.share-link-feedback');
    if (!o) return;
    o.textContent = text;
    o.style.display = "";
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { o.style.display = "none"; }, ms);
}

function shareLink() {
    showToast('Link copied');

    const url = window.location.origin + window.location.pathname + '?folder=' + encodeURIComponent((currentData && currentData.CurrentPath) || localStorage.getItem('lastFolderPath') || basePath);

    if (navigator.clipboard) {
        navigator.clipboard.writeText(url);
    } else {
        const tempInput = document.createElement('input');
        tempInput.value = url;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
    }
}

function setupFaveStar() {
    const star = document.getElementById('faveStar');
    star.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();

        if (!currentData || !currentData.CurrentPath) return;

        const desired = !currentIsFave;
        const ok = await setFave(currentData.CurrentPath, desired);
        if (ok) setFaveUI(desired);
    });
}

function setFaveUI(isFave) {
    currentIsFave = !!isFave;
    const star = document.getElementById('faveStar');
    if (!star) return;
    star.classList.toggle('is-on', currentIsFave);
    star.setAttribute('aria-pressed', currentIsFave ? 'true' : 'false');
    star.title = currentIsFave ? 'Remove bookmark' : 'Bookmark this book';
    const label = star.querySelector('.act-label');
}

async function refreshFaveUIForCurrentFolder() {
    setFaveUI(false);

    const star = document.getElementById('faveStar');
    if (star) star.style.display = ''; // Always show for authenticated users

    if (!currentData || !currentData.CurrentPath) return;

    try {
        const r = await fetch('?mode=getFave&folderPath=' + encodeURIComponent(currentData.CurrentPath), { cache: 'no-store' });
        const data = await r.json();
        if (data && data.ok) setFaveUI(!!data.isFave);
    } catch (e) {
        // ignore
    }
}

async function setFave(folderPath, isFave) {
    const data = new URLSearchParams();
    data.append('folderPath', folderPath);
    data.append('isFave', isFave ? '1' : '0');

    try {
        const r = await fetch('?mode=setFave', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data.toString()
        });
        const resp = await r.json();
        return !!(resp && resp.ok);
    } catch (e) {
        return false;
    }
}

// Handle window resize for responsive DataTable behavior (debounced)
let resizeTimer = null;
window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
        if (currentData && currentData.Subfolders.length > 0) {
            const wasWideScreen = document.getElementById('subfoldersTable') !== null;
            const isWideScreen = window.innerWidth > 1000;

            // Only re-render if the display mode should change
            if (wasWideScreen !== isWideScreen) {
                renderContent(currentData);
            }
        }
    }, 200);
});

function loadFolder(path) {
    localStorage.setItem('lastFolderPath', path);
    ShowSpinner();
    const myRequest = ++folderRequestSeq;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '?mode=json', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (myRequest !== folderRequestSeq) return; // a newer folder was requested
            HideSpinner();
            if (xhr.status === 200) {
                try {
                    if (xhr.responseText == "") {
                        localStorage.removeItem('lastFolderPath');
                        showError('Empty text for: ' + path);
                    } else {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) throw new Error(data.error);
                        currentData = data; // Store data for resize handler
                        renderBreadcrumb(data);
                        renderRatings(data);
                        renderContent(data);
                        if (document.getElementById('editFolderModal')) {
                            SetModal(data);
                        }
                        // Update star state for the folder (logged-in users only)
                        refreshFaveUIForCurrentFolder();
                    }
                } catch (err) {
                    showError(err.message);
                }
            } else {
                showError('Server error: ' + xhr.statusText);
            }
        }
    };
    xhr.onerror = function () {
        if (myRequest !== folderRequestSeq) return;
        HideSpinner();
        showError('Network error');
    };
    xhr.send('folderPath=' + encodeURIComponent(path));
}

function GetMyRating(data) {
    if (data.MyRating && data.MyRating != "") {
        var s = data.MyRating;
        if (parseInt(s) == parseFloat(s)) {
            s += ".0"; // Ensure it has one decimal place
        }
        return s;
    } else {
        return "";
    }
}

// Page header: the current folder's title, plus author/rating/link when known.
function renderRatings(data) {
    const el = document.getElementById('ratings');
    const title = data.Title || folderDisplayName(data.CurrentPath);
    const isBook = data.Mp3Files && data.Mp3Files.length > 0;

    const meta = [];
    if (data.Author) {
        meta.push('<span class="book-author">' + escapeHtml(data.Author) + '</span>');
    }
    if (data.TitleRating !== "" && data.TitleRating !== undefined) {
        let r = '<span class="book-rating"><span class="star-glyph" aria-hidden="true">★</span> ' + escapeHtml(data.TitleRating);
        if (data.RateCount) {
            const n = parseInt(data.RateCount, 10);
            r += ' <span class="muted">' + escapeHtml(isNaN(n) ? data.RateCount : n.toLocaleString()) + ' ratings</span>';
        }
        meta.push(r + '</span>');
    }
    if (data.MyRating) {
        meta.push('<span class="book-myrating"><span class="muted">You</span> ' + ratingImgHtml(data.MyRating) + '</span>');
    }
    const url = safeUrl(data.TitleUrl);
    if (url) {
        meta.push('<a class="book-link" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">Book page <svg class="icon icon-sm"><use href="#i-external"/></svg></a>');
    }

    el.innerHTML = '<h1 class="page-title' + (isBook ? ' is-book' : '') + '">' + escapeHtml(title) + '</h1>'
        + (meta.length ? '<div class="book-meta">' + meta.join('') + '</div>' : '');
    document.title = title === 'Library' ? 'Audiobooks' : title;
}

function relativeParts(path) {
    let relative = String(path || '').replace(basePath, '');
    relative = relative.replace(/^[\\/]+/, '');
    return relative ? relative.split(sFolderSep) : [];
}

function folderDisplayName(path) {
    const parts = relativeParts(path);
    return parts.length ? parts[parts.length - 1] : 'Library';
}

// Five stars filled to the rating (CSS draws them; see .stars in Player.css)
function ratingImgHtml(rating) {
    let n = parseFloat(rating);
    if (!isFinite(n)) n = 0;
    n = Math.max(0, Math.min(5, n));
    return '<span class="stars" style="--r:' + n + '" data-rating="' + n + '" role="img" aria-label="' + n + ' out of 5" title="' + n + ' out of 5"></span>';
}

function renderBreadcrumb(data) {
    const parts = relativeParts(data.CurrentPath);
    const el = document.getElementById('breadcrumb');
    if (parts.length === 0) {
        el.innerHTML = '';
        return;
    }

    const sep = '<svg class="icon crumb-sep" aria-hidden="true"><use href="#i-chevron"/></svg>';
    let html = '<span class="crumb" data-path="' + escapeHtml(basePath) + '">Library</span>';
    let accumulated = basePath;

    for (let i = 0; i < parts.length - 1; i++) {
        accumulated += sFolderSep + parts[i];
        html += sep + '<span class="crumb" data-path="' + escapeHtml(accumulated) + '">' + escapeHtml(parts[i]) + '</span>';
    }

    el.innerHTML = html;
}

function renderContent(data) {
    const container = document.getElementById('content');
    let html = '';
    const selector = document.getElementById("trackSelector");
    const playerControl = document.getElementById("playerControl");
    selector.length = 0;
    playerControl.style.display = "none";
    document.body.classList.toggle('view-player', data.Subfolders.length === 0 && data.Mp3Files.length > 0);

    // Clean up existing DataTable if it exists
    if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#subfoldersTable')) {
        $('#subfoldersTable').DataTable().destroy();
    }

    if (data.Subfolders.length > 0) {
        const isWideScreen = window.innerWidth > 1000;
        const hasDataTables = typeof $ !== 'undefined' && $.fn.DataTable;

        if (isWideScreen && hasDataTables) {
            // Use DataTables for wide screens. Every value is escaped: titles, authors
            // and URLs can be edited by users, and folder names can contain quotes.
            const tableData = data.Subfolders.map(o => {
                const f = o.Folder;
                const displayName = o.Title !== "" ? o.Title : f.split(sFolderSep).pop();
                const url = safeUrl(o.TitleUrl);
                const titleUrl = url
                    ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="AmazonLink icon-link" aria-label="Book page" title="Book page"><svg class="icon"><use href="#i-external"/></svg></a>`
                    : '';
                const myRating = o.MyRating !== "" ? ratingImgHtml(o.MyRating) : '';

                let formattedRateCount = o.RateCount || '';
                if (formattedRateCount && !isNaN(formattedRateCount)) {
                    formattedRateCount = parseInt(formattedRateCount, 10).toLocaleString();
                }

                return [
                    `<span class="folder" data-path="${escapeHtml(f)}">${escapeHtml(displayName)}</span>`,
                    escapeHtml(o.TitleRating),
                    formattedRateCount,
                    myRating,
                    escapeHtml(o.Author),
                    escapeHtml(o.Category),
                    escapeHtml(o.PubYear),
                    `<button type="button" class="edit-folder-btn" data-path="${escapeHtml(f)}">Edit</button>`,
                    titleUrl
                ];
            });

            container.innerHTML = `<table id="subfoldersTable" class="display" style="width:100%">
    <thead>
        <tr>
            <th>Title</th>
            <th>Rating</th>
            <th>Ratings</th>
            <th>My rating</th>
            <th>Author</th>
            <th>Category</th>
            <th>Year</th>
            <th></th>
            <th>Link</th>
        </tr>
    </thead>
    <tbody></tbody>
</table>`;

            $('#subfoldersTable').DataTable({
                data: tableData,
                pageLength: 100,
                lengthChange: false, // Hide the "Show X entries" dropdown
                paging: tableData.length > 100, // Only show paging if more than 100 rows
                info: tableData.length > 100, // Hide the "Showing X to Y of Z entries" section
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [7] }, // Disable sorting for Edit only
                    {
                        targets: 2, // Rate Count column
                        type: 'num',
                        render: function (data, type, row) {
                            if (type === 'sort' || type === 'type') {
                                return data ? parseInt(String(data).replace(/[^\d]/g, ''), 10) || 0 : 0;
                            }
                            return data;
                        }
                    },
                    {
                        targets: 3, // My Rating column
                        type: 'num',
                        render: function (data, type, row) {
                            if (type === 'sort' || type === 'type') {
                                // Extract numeric value from image src
                                const match = data && String(data).match(/data-rating="([\d.]+)"/);
                                if (match) return parseFloat(match[1]);
                                return 0; // No rating
                            }
                            return data;
                        }
                    },
                    {
                        targets: 8, // Link column
                        render: function (data, type, row) {
                            if (type === 'sort' || type === 'type') {
                                if (data && data.includes('href=')) {
                                    const match = data.match(/href=['"]([^'"]*)['"]/);
                                    if (match) return match[1];
                                }
                                return '';
                            }
                            return data;
                        }
                    },
                    { width: "32%", targets: 0 }, // Title
                    { width: "7%", targets: 1 },  // Rating
                    { width: "8%", targets: 2 },  // Number of ratings
                    { width: "10%", targets: 3 }, // My rating
                    { width: "17%", targets: 4 }, // Author
                    { width: "12%", targets: 5 }, // Category
                    { width: "6%", targets: 6 },  // Year
                    { width: "4%", targets: 7 },  // Edit
                    { width: "4%", targets: 8 }   // Link
                ]
            });

            // Edit buttons. Folder-name clicks are handled by the global click handler
            // below; adding a second handler here used to load every folder twice.
            $('#subfoldersTable tbody').on('click', '.edit-folder-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const path = this.dataset.path;
                if (path) {
                    fetchFolderDataForEdit(path);
                }
            });
        } else {
            // Simple list for narrow screens (and as a fallback if DataTables didn't load)
            if (isWideScreen) console.warn('DataTables not available, falling back to list view');
            data.Subfolders.forEach(o => { html += buildFolderListItem(o); });
            container.innerHTML = '<ul class="folder-list">' + html + "</ul>";
        }

    } else if (data.Mp3Files.length > 0) {

        playerControl.style.display = "";
        sCurrentFolder = data.CurrentFolder;

        let audioCount = 0;
        data.Mp3Files.forEach((f, index) => {
            const name = f.split('/').pop();
            if (sCurrentFolder !== "") {
                f = sCurrentFolder + "/" + f;
            }
            const isAudio = f.toLowerCase().endsWith(".mp3");
            const ch = chapterParts(name, index);

            html += `<li class="file${isAudio ? '' : ' is-doc'}" data-path="${escapeHtml(f)}" data-index="${index}">`
                + `<span class="ch-num">${escapeHtml(ch.num)}</span>`
                + `<svg class="icon ch-check" aria-hidden="true"><use href="#i-check"/></svg>`
                + `<span class="ch-title">${escapeHtml(ch.title)}</span>`
                + (isAudio
                    ? `<span class="downloaded" title="Available offline"><svg class="icon icon-sm"><use href="#i-saved"/></svg></span>`
                    : `<span class="file-kind">${escapeHtml(ch.ext.toUpperCase())}</span>`)
                + `<span class="ch-progress" aria-hidden="true"></span>`
                + `</li>`;

            if (isAudio) {
                audioCount++;
                const option = document.createElement("option");
                option.value = f;
                option.textContent = ch.title;
                selector.appendChild(option);
            }
        });

        container.innerHTML = `<h3 class="list-heading">${audioCount} ${audioCount === 1 ? 'chapter' : 'chapters'}</h3>`
            + '<ul class="chapter-list">' + html + "</ul>";
        loadTracks(data.CurrentPath);
        checkFolderCache();
    } else {
        container.innerHTML = '<p class="empty">This folder has no audiobooks or MP3 files yet.</p>';
    }

    // Keep external-link clicks from also opening the folder
    container.querySelectorAll('a.AmazonLink').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });
}

// One <li> for the narrow-screen folder list
function buildFolderListItem(o) {
    const f = o.Folder;
    const title = o.Title !== "" ? o.Title : f.split(sFolderSep).pop();

    const meta = [];
    if (o.Author) meta.push('<span>' + escapeHtml(o.Author) + '</span>');
    if (o.TitleRating !== "") {
        let r = '<span class="book-rating"><span class="star-glyph" aria-hidden="true">★</span> ' + escapeHtml(o.TitleRating);
        if (o.RateCount !== "") {
            const n = parseInt(o.RateCount, 10);
            r += ' <span class="muted">(' + escapeHtml(isNaN(n) ? o.RateCount : n.toLocaleString()) + ')</span>';
        }
        meta.push(r + '</span>');
    }
    if (o.MyRating != "") meta.push('<span class="book-myrating"><span class="muted">You</span> ' + ratingImgHtml(o.MyRating) + '</span>');

    const url = safeUrl(o.TitleUrl);
    const link = url
        ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="AmazonLink icon-link" aria-label="Book page" title="Book page"><svg class="icon"><use href="#i-external"/></svg></a>`
        : '';

    return '<li class="folder" data-path="' + escapeHtml(f) + '">'
        + '<span class="folder-text"><span class="folder-title">' + escapeHtml(title) + '</span>'
        + (meta.length ? '<span class="folder-meta">' + meta.join('') + '</span>' : '')
        + '</span>' + link
        + '<svg class="icon chevron" aria-hidden="true"><use href="#i-chevron"/></svg></li>';
}

function showError(msg) {
    HideSpinner();
    document.getElementById('content').innerHTML =
        '<div style="color:red;"><strong>Error:</strong> ' + escapeHtml(msg) + '</div>';
    document.getElementById('breadcrumb').innerHTML = '';
}

document.addEventListener('click', function (e) {
    const target = e.target.closest('[data-path]');
    if (!target) return;

    const path = target.dataset.path;

    if (target.classList.contains('folder') || target.classList.contains('crumb')) {
        loadFolder(path);
    } else if (target.classList.contains('file')) {
        if (!path.toLowerCase().endsWith(".mp3")) {
            window.open(path);
        } else {
            const selector = document.getElementById("trackSelector");
            selector.value = path;
            if (selector.selectedIndex !== -1) {
                const audio = document.getElementById("audioPlayer");
                pendingResumeFolder = null; // user's choice wins over a still-loading resume
                setAudioFileAndPlay(audio, selector.value);
                if (playingFolderPath) setServerProgress(playingFolderPath, 0, selector.value);
            }
        }
    }
});

async function getServerProgress() {
    const r = await fetch('?mode=getProgress', { cache: 'no-store' });
    if (!r.ok) return { ok: false };
    return await r.json();
}

// NEW: per-folder resume (UserFolder)
async function getServerFolderProgress(folderPath) {
    const r = await fetch('?mode=getFolderProgress&folderPath=' + encodeURIComponent(folderPath || ''), { cache: 'no-store' });
    if (!r.ok) return { ok: false };
    return await r.json();
}

async function setServerProgress(folderPath, timeSeconds, fileUrl) {
    const data = new URLSearchParams();
    data.append('folderPath', folderPath);
    data.append('timeSeconds', timeSeconds);
    data.append('fileUrl', fileUrl || '');

    try {
        await fetch('?mode=setProgress', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data.toString()
        });
    } catch (e) {
        // ignore errors
    }
}

async function resolveInitialFolder(folderParam) {
    if (folderParam) {
        return folderParam;
    }

    try {
        const prog = await getServerProgress();
        if (prog && prog.ok && prog.lastFolderPath) {
            return prog.lastFolderPath;
        }
    } catch (e) {
        // ignore and fallback
    }

    return localStorage.getItem('lastFolderPath') || basePath;
}

// Listeners and timers that must exist exactly once for the page's lifetime.
// (They used to be added on every folder visit, which stacked up duplicates:
// several 'ended' handlers skipped tracks, several timers spammed setProgress.)
function initPlayerOnce() {
    if (playerInitialized) return;
    playerInitialized = true;

    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");
    if (!audio || !selector) return;

    initPlayerUI(audio);

    // User picked a track from the dropdown: start it from the beginning.
    selector.addEventListener("change", () => {
        pendingResumeFolder = null;
        setAudioFileAndPlay(audio, selector.value);
        if (playingFolderPath) {
            setServerProgress(playingFolderPath, 0, selector.value);
        }
    });

    // Auto-play next track
    audio.addEventListener("ended", () => {
        const currentIndex = selector.selectedIndex;
        if (currentIndex >= 0 && currentIndex < selector.options.length - 1) {
            const nextOption = selector.options[currentIndex + 1];
            selector.value = nextOption.value;
            setAudioFileAndPlay(audio, nextOption.value);
        }
    });

    // Save progress every 5 seconds while playing, and right away on pause.
    setInterval(() => saveCurrentProgress(false), 5000);
    audio.addEventListener('pause', () => saveCurrentProgress(true));

    // Update highlights/progress bar every 500ms
    setInterval(updateHighlights, 500);
}

function saveCurrentProgress(evenIfPaused) {
    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");
    if (!audio || !audio.src || !playingFolderPath) return;
    if (audio.readyState < 1) return; // a new track is still loading; position isn't meaningful yet
    if (audio.paused && !evenIfPaused) return;
    // Don't save while a resume is still loading, or we'd overwrite the saved spot with 0.
    if (pendingResumeFolder === playingFolderPath) return;

    const secs = Math.floor(audio.currentTime || 0);
    const fileUrl = selector && selector.value ? selector.value : '';
    const key = playingFolderPath + '|' + fileUrl + '|' + secs;
    if (key === lastSavedProgressKey) return;
    lastSavedProgressKey = key;

    setServerProgress(playingFolderPath, secs, fileUrl);
}

// Called for each folder that contains MP3s, after the selector has been filled.
// Resumes the saved track/position for this folder, otherwise starts at track 1.
async function loadTracks(folderPath) {
    initPlayerOnce();

    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");
    const myLoad = ++trackLoadSeq;

    playingFolderPath = folderPath || '';
    lastSavedProgressKey = '';
    playingBook = {
        title: (currentData && (currentData.Title || folderDisplayName(currentData.CurrentPath))) || '',
        author: (currentData && currentData.Author) || ''
    };
    if (selector.length === 0) return;

    let startFile = selector.options[0].value;
    let startTime = 0;

    pendingResumeFolder = playingFolderPath;
    try {
        const prog = await getServerFolderProgress(playingFolderPath);
        if (myLoad !== trackLoadSeq) return; // user already moved on

        if (prog && prog.ok && prog.lastFileUrl) {
            const desired = String(prog.lastFileUrl);
            const exists = Array.from(selector.options).some(o => o.value === desired);
            if (exists) {
                startFile = desired;
                startTime = Math.max(0, Number(prog.lastTimeSeconds) || 0);
            }
        }
    } catch (e) {
        // no saved progress; start at the beginning
    }
    if (myLoad !== trackLoadSeq) return;

    // If the user picked a track while progress was loading, respect that choice.
    if (pendingResumeFolder !== playingFolderPath) return;

    selector.value = startFile;
    await setAudioFile(audio, startFile, startTime);
    pendingResumeFolder = null;
    if (myLoad !== trackLoadSeq) return;

    playAudio(audio);
}

function updateHighlights() {
    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");
    const currentPath = selector.value;
    const fileElements = document.querySelectorAll("#content .file");

    let currentIndex = -1;
    fileElements.forEach((el, i) => {
        if (el.dataset.path === currentPath) currentIndex = i;
    });

    fileElements.forEach((el, i) => {
        const isCurrent = i === currentIndex;
        el.classList.toggle("is-done", i < currentIndex && !el.classList.contains('is-doc'));
        el.classList.toggle("is-current", isCurrent);
        if (isCurrent) {
            el.setAttribute('aria-current', 'true');
            const pct = audio.duration > 0 ? (audio.currentTime / audio.duration) * 100 : 0;
            el.style.setProperty('--p', pct.toFixed(2) + '%');
        } else {
            el.removeAttribute('aria-current');
            el.style.removeProperty('--p');
        }
    });

    updateNowPlaying();
}

function goForwardSec(sec) {
    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");

    const forward = Math.abs(Number(sec) || 0);
    if (forward <= 0) return;

    // If metadata not loaded yet, fall back to a simple add.
    const canUseDuration = Number.isFinite(audio.duration) && audio.duration > 0;

    if (!canUseDuration) {
        audio.currentTime = Math.max(0, (audio.currentTime || 0) + forward);
        return;
    }

    const targetTime = (audio.currentTime || 0) + forward;

    // Within current track
    if (targetTime < audio.duration) {
        audio.currentTime = targetTime;
        return;
    }

    // Spill into next track
    const remaining = targetTime - audio.duration;
    const currentIndex = selector.selectedIndex;

    // No next track: clamp to end
    if (currentIndex < 0 || currentIndex >= selector.options.length - 1) {
        audio.currentTime = audio.duration;
        return;
    }

    const nextOption = selector.options[currentIndex + 1];
    selector.value = nextOption.value;
    setAudioFileAndPlay(audio, nextOption.value, dur => Math.min(remaining, dur));
}

function goBackSec(sec) {
    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");

    if (audio.currentTime > sec) {
        audio.currentTime -= sec;
        return;
    }

    const currentIndex = selector.selectedIndex;
    if (currentIndex > 0) { // was "> 1", which blocked going back from track 2 to track 1
        const prevOption = selector.options[currentIndex - 1];
        const offset = sec - audio.currentTime;
        selector.value = prevOption.value;
        setAudioFileAndPlay(audio, prevOption.value, dur => Math.max(0, dur - offset));
    } else {
        audio.currentTime = 0;
    }
}

function ShowSpinner() {
    document.getElementById('spinnerContainer').style.display = "";
    const audio = document.getElementById("audioPlayer");
    if (audio) audio.pause();
}

function HideSpinner() {
    document.getElementById('spinnerContainer').style.display = "none";
}

function OpenFolderDialog(bTable, data) {
    editDialogOpenedFromTable = bTable;
    const folderData = data || currentData;
    if (folderData) SetModal(folderData);

    // Only the user's own rating is editable when shared metadata is admin-only.
    const canEditShared = window.CAN_EDIT_SHARED !== false;
    ['editTitle', 'editTitleUrl', 'editRate', 'editRateCount', 'editAuthor', 'editCategory', 'editPublicationDate']
        .forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = !canEditShared;
        });

    const msg = document.getElementById('editFolderMsg');
    if (msg) msg.textContent = '';

    document.getElementById("editFolderModal").showModal();
}

function SetModal(folderData) {
    document.getElementById('editFolderPath').value = folderData.CurrentPath || '';
    document.getElementById('editTitle').value = folderData.Title || '';
    document.getElementById('editTitleUrl').value = folderData.TitleUrl || '';
    document.getElementById('editMyRating').value = GetMyRating(folderData);
    document.getElementById('editRate').value = folderData.TitleRating || '';
    document.getElementById('editRateCount').value = folderData.RateCount || '';
    document.getElementById('editAuthor').value = folderData.Author || '';
    document.getElementById('editCategory').value = folderData.Category || '';
    document.getElementById('editPublicationDate').value = folderData.PubDate || '';

    let relPath = folderData.CurrentPath || '';
    if (relPath.startsWith(basePath)) {
        relPath = relPath.substring(basePath.length);
        relPath = relPath.replace(/^[/\\]+/, '');
    }

    document.getElementById('editModalHeader').textContent = relPath;
}

function CloseFolderDialog() {
    document.getElementById("editFolderModal").close();
}

function SaveFolderDialog() {
    const data = new URLSearchParams();
    const savedPath = document.getElementById('editFolderPath').value;
    data.append('folderPath', savedPath);
    data.append('title', document.getElementById('editTitle').value);
    data.append('titleUrl', document.getElementById('editTitleUrl').value);
    data.append('myRating', document.getElementById('editMyRating').value);
    data.append('rate', document.getElementById('editRate').value);
    data.append('rateCount', document.getElementById('editRateCount').value);
    data.append('author', document.getElementById('editAuthor').value);
    data.append('category', document.getElementById('editCategory').value);
    data.append('publicationDate', document.getElementById('editPublicationDate').value);

    const msg = document.getElementById('editFolderMsg');
    if (msg) msg.textContent = '';

    // Don't use ShowSpinner() here: it pauses playback, and saving a rating
    // shouldn't stop the book you're listening to.
    document.getElementById('spinnerContainer').style.display = "";

    fetch('?mode=updateFolder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
        .then(r => r.json())
        .then(resp => {
            HideSpinner();
            if (!resp.success) {
                if (msg) msg.textContent = resp.error || 'Update failed.';
                else alert(resp.error || 'Update failed.');
                return;
            }
            CloseFolderDialog();

            const isPlayerView = currentData && currentData.Mp3Files && currentData.Mp3Files.length > 0;
            if (isPlayerView && !editDialogOpenedFromTable) {
                // Edited the book that's open in the player: refresh only the header,
                // so playback isn't restarted.
                refreshCurrentFolderInfo();
            } else {
                // Edited a row in the folder listing: reload the listing.
                loadFolder(currentData ? currentData.CurrentPath : basePath);
            }
        })
        .catch(err => {
            HideSpinner();
            if (msg) msg.textContent = err.message;
            else alert(err.message);
        });
}

// Re-read the current folder's metadata and update the header without re-rendering the player.
function refreshCurrentFolderInfo() {
    if (!currentData) return;
    const path = currentData.CurrentPath;
    fetch('?mode=json', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'folderPath=' + encodeURIComponent(path)
    })
        .then(r => r.json())
        .then(data => {
            if (data.error || !currentData || currentData.CurrentPath !== path) return;
            ['Title', 'TitleUrl', 'TitleRating', 'MyRating', 'RateCount', 'Author', 'Category', 'PubYear', 'PubDate']
                .forEach(k => { currentData[k] = data[k]; });
            renderRatings(currentData);
            SetModal(currentData);
        })
        .catch(() => { /* header just stays as it was */ });
}

// Fetch folder data for editing (does not re-render the view)
function fetchFolderDataForEdit(path) {
    document.getElementById('spinnerContainer').style.display = "";
    fetch('?mode=json', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'folderPath=' + encodeURIComponent(path)
    })
        .then(r => { HideSpinner(); return r.json(); })
        .then(data => {
            if (data.error) throw new Error(data.error);
            // Do NOT set currentData = data;
            OpenFolderDialog(true, data);
        })
        .catch(err => {
            HideSpinner();
            alert('Error loading folder for edit: ' + err.message);
        });
}

function playAudio(audio) {
    resumeAudioContext();
    const p = audio.play();
    if (p && typeof p.catch === 'function') {
        // Autoplay can be blocked until the user interacts with the page; that's fine,
        // the Play button still works.
        p.catch(err => console.warn('Playback did not start:', err && err.message));
    }
}

// startTime: seconds, or a function (duration) => seconds for seeks that depend
// on the new track's length (used by +30 / -30 across track boundaries).
function setAudioFileAndPlay(audio, sFile, startTime = 0) {
    setAudioFile(audio, sFile, startTime)
        .then(() => playAudio(audio))
        .catch(err => console.error('Error setting audio file:', err));
}

async function setAudioFile(audio, sFile, startTime = 0) {
    const myToken = ++audioLoadToken;

    // When switching tracks, show "Play" until playback actually starts.
    setPlayButtonsState(false);

    let src = sFile;
    let objectUrl = null;
    try {
        if ('caches' in window) {
            const cache = await caches.open('mp3-cache');
            const response = await cache.match(sFile);
            if (response) {
                objectUrl = URL.createObjectURL(await response.blob());
                src = objectUrl;
            }
        }
    } catch (e) {
        // Cache unavailable (e.g. plain http): stream from the server instead.
    }

    // A newer track was requested while we were reading the cache.
    if (myToken !== audioLoadToken) {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        return;
    }

    // Free the previous cached track's memory before switching.
    if (currentObjectUrl) URL.revokeObjectURL(currentObjectUrl);
    currentObjectUrl = objectUrl;

    const needsSeek = typeof startTime === 'function' || startTime > 0;
    const metadataReady = needsSeek
        ? new Promise(resolve => {
            audio.addEventListener('loadedmetadata', function onMeta() {
                audio.removeEventListener('loadedmetadata', onMeta);
                if (myToken === audioLoadToken) {
                    const dur = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0;
                    let t = typeof startTime === 'function' ? startTime(dur) : startTime;
                    if (dur > 0) t = Math.min(t, Math.max(0, dur - 1));
                    try { audio.currentTime = Math.max(0, t || 0); } catch (e) { /* ignore */ }
                }
                resolve();
            });
        })
        : null;

    audio.src = src;

    // Wait for the seek so playback starts at the right spot, not at 0:00 then jumps.
    if (metadataReady) {
        await Promise.race([metadataReady, new Promise(r => setTimeout(r, 15000))]);
    }
}

async function cacheFolder() {
    const btn = document.getElementById("btnCacheFolder");
    if (!('caches' in window)) {
        alert('Offline downloads need the site to be opened over HTTPS.');
        return;
    }
    const selector = document.getElementById("trackSelector");
    const total = selector.options.length;
    btn.disabled = true;

    try {
        for (let i = 0; i < total; i++) {
            setCacheButton('busy', `${i + 1} of ${total}`);
            try {
                await downloadAndCache(selector.options[i].value, i);
            } catch (e) {
                console.error('Caching failed for', selector.options[i].value, e);
                setCacheIcon(i, false);
            }
        }
    } finally {
        btn.disabled = false;
        await checkFolderCache();
    }
}

// state: 'idle' | 'busy' | 'done'
function setCacheButton(state, text) {
    const btn = document.getElementById("btnCacheFolder");
    if (!btn) return;
    btn.classList.toggle('is-on', state === 'done');
    btn.classList.toggle('is-busy', state === 'busy');
    const label = btn.querySelector('.act-label');
    if (label) label.textContent = text || (state === 'done' ? 'Offline' : 'Download');
    btn.title = state === 'done' ? 'All chapters are saved for offline listening' : 'Save all chapters for offline listening';
}

async function downloadAndCache(sFile, i) {
    const cache = await caches.open('mp3-cache');
    const match = await cache.match(sFile);
    if (match) {
        setCacheIcon(i, true);
        return;
    }

    const response = await fetch(sFile);

    if (!response.ok) {
        setCacheIcon(i, false);
        return;
    }

    await cache.put(sFile, response);
    setCacheIcon(i, true);
}

async function checkFolderCache() {
    if (!('caches' in window)) return;
    const selector = document.getElementById("trackSelector");
    const total = selector.options.length;
    let cached = 0;
    for (let i = 0; i < total; i++) {
        try {
            if (await checkCache(selector.options[i].value, i)) cached++;
        } catch (e) {
            // ignore
        }
    }
    const btn = document.getElementById("btnCacheFolder");
    if (btn && !btn.disabled) setCacheButton(total > 0 && cached === total ? 'done' : 'idle');
}

async function checkCache(sFile, i) {
    const cache = await caches.open('mp3-cache');
    const match = await cache.match(sFile);
    setCacheIcon(i, !!match);
    return !!match;
}

function setCacheIcon(i, match) {
    // The user may have navigated to another folder while this was running.
    const li = document.querySelector(`li.file[data-index="${i}"]`);
    if (li) li.classList.toggle('is-cached', !!match);
}

// Builds the Web Audio chain the first time it's needed (boost above 100% or
// Speaker mode). This is one-way: once an <audio> element is routed through Web
// Audio it stays that way, so "off" settings bypass the nodes rather than
// disconnecting them.
//
//   source -> highpass -> presence EQ -> compressor -> boost -> limiter -> master -> out
//
// Must be called from a user gesture (click/input), or the context starts suspended.
function initializeAudioGain() {
    if (bAudioGaininitialized) return;
    bAudioGaininitialized = true;

    const audioElement = document.getElementById('audioPlayer');
    const Ctx = window.AudioContext || window.webkitAudioContext;
    audioCtx = new Ctx();
    const source = audioCtx.createMediaElementSource(audioElement);

    // Removes rumble small speakers can't reproduce anyway (frees up headroom)
    const hp = audioCtx.createBiquadFilter();
    hp.type = 'highpass';
    hp.Q.value = 0.7;

    // Lift the 2-4 kHz band where consonants live: better intelligibility
    const presence = audioCtx.createBiquadFilter();
    presence.type = 'peaking';
    presence.frequency.value = 3000;
    presence.Q.value = 0.9;

    // Evens out the narrator: quiet syllables come up, peaks come down.
    // (The browser adds its own make-up gain, so no extra gain is needed here.)
    const comp = audioCtx.createDynamicsCompressor();
    comp.attack.value = 0.003;
    comp.release.value = 0.25;

    // Volume above 100%
    gainNode = audioCtx.createGain();

    // Safety limiter so boosted audio doesn't crackle
    const limiter = audioCtx.createDynamicsCompressor();
    limiter.threshold.value = -3;
    limiter.knee.value = 0;
    limiter.ratio.value = 20;
    limiter.attack.value = 0.001;
    limiter.release.value = 0.1;

    // Volume below 100% (applied last so the limiter always sees the full signal)
    const master = audioCtx.createGain();

    source.connect(hp);
    hp.connect(presence);
    presence.connect(comp);
    comp.connect(gainNode);
    gainNode.connect(limiter);
    limiter.connect(master);
    master.connect(audioCtx.destination);

    voiceNodes = { hp, presence, comp, boost: gainNode, limiter, master };

    audioElement.addEventListener('play', resumeAudioContext);
}

// Safari may leave the context 'suspended' or 'interrupted'; try to bring it back.
function resumeAudioContext() {
    if (audioCtx && audioCtx.state !== 'running' && audioCtx.state !== 'closed') {
        audioCtx.resume().catch(() => { /* needs a user gesture; the next tap will do it */ });
    }
}

function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPadOS reports as Mac
}

function volumeNeedsWebAudio() {
    return volumePct > 100 || speakerMode;
}

function initVolumeControls() {
    const button = document.getElementById('volumeButton');
    const panel = document.getElementById('volumePanel');
    if (!button) return;

    if (isIOS()) {
        // iPhone/iPad (every browser there uses Safari's engine): Web Audio stops
        // playback when the screen locks, and audio.volume is read-only, so neither
        // the slider nor Speaker mode can work. Use the hardware buttons instead.
        button.remove();
        if (panel) panel.remove();
        return;
    }

    // Restore saved settings (this device only)
    try {
        const v = parseInt(localStorage.getItem('volumePct'), 10);
        if (!isNaN(v)) volumePct = Math.max(0, Math.min(VOLUME_MAX, v));
        speakerMode = localStorage.getItem('speakerMode') === '1';
    } catch (e) { /* ignore */ }

    // Web Audio has to start from a user gesture. If the saved settings need it,
    // switch it on at the first tap/keypress; until then play at plain 100%.
    if (volumeNeedsWebAudio()) {
        const startOnGesture = () => {
            document.removeEventListener('click', startOnGesture, true);
            document.removeEventListener('keydown', startOnGesture, true);
            if (volumeNeedsWebAudio() && !bAudioGaininitialized) initializeAudioGain();
            resumeAudioContext();
            applyVolume();
        };
        document.addEventListener('click', startOnGesture, true);
        document.addEventListener('keydown', startOnGesture, true);
    }

    button.setAttribute('aria-expanded', 'false');
    if (panel) button.setAttribute('aria-controls', 'volumePanel');
    button.addEventListener('click', () => toggleVolumePanel());

    const slider = document.getElementById('volumeSlider');
    if (slider) {
        slider.max = String(VOLUME_MAX);
        slider.value = String(volumePct);
        slider.addEventListener('input', () => {
            let v = parseInt(slider.value, 10) || 0;
            if (Math.abs(v - 100) <= VOLUME_SNAP) v = 100;   // easy to get back to normal
            setVolume(v, false);
        });
        slider.addEventListener('change', () => saveVolumeSettings());
    }

    const speakerSwitch = document.getElementById('speakerSwitch');
    if (speakerSwitch) {
        speakerSwitch.addEventListener('click', () => {
            speakerMode = !speakerMode;
            if (speakerMode && !bAudioGaininitialized) initializeAudioGain();
            resumeAudioContext();
            applyVolume();
            saveVolumeSettings();
        });
    }

    const reset = document.getElementById('volumeReset');
    if (reset) {
        reset.addEventListener('click', () => {
            speakerMode = false;
            setVolume(100, true);
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && panel && !panel.hidden) {
            toggleVolumePanel(false);
            button.focus();
        }
    });

    applyVolume();
}

function toggleVolumePanel(open) {
    const button = document.getElementById('volumeButton');
    const panel = document.getElementById('volumePanel');
    if (!panel || !button) return;
    const show = typeof open === 'boolean' ? open : panel.hidden;
    panel.hidden = !show;
    button.setAttribute('aria-expanded', String(show));
    button.classList.toggle('is-open', show);
    if (show) panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function setVolume(pct, save) {
    volumePct = Math.max(0, Math.min(VOLUME_MAX, Math.round(pct)));
    if (volumeNeedsWebAudio() && !bAudioGaininitialized) initializeAudioGain();
    resumeAudioContext();
    applyVolume();
    if (save) saveVolumeSettings();
}

function saveVolumeSettings() {
    try {
        localStorage.setItem('volumePct', String(volumePct));
        localStorage.setItem('speakerMode', speakerMode ? '1' : '0');
    } catch (e) { /* ignore */ }
}

// Applies volumePct/speakerMode to the audio and updates the button + panel.
function applyVolume() {
    const audio = document.getElementById('audioPlayer');
    const level = volumePct / 100;

    if (voiceNodes && audioCtx) {
        // Web Audio is on: all volume handling happens in the graph.
        const t = audioCtx.currentTime;
        const ramp = (param, value) => param.setTargetAtTime(value, t, 0.03); // no clicks
        const n = voiceNodes;

        if (audio) audio.volume = 1;
        ramp(n.boost.gain, Math.max(level, 1));
        ramp(n.master.gain, Math.min(level, 1));

        // Speaker mode on: voice chain. Off: each stage becomes a pass-through.
        ramp(n.hp.frequency, speakerMode ? 120 : 10);
        ramp(n.presence.gain, speakerMode ? 4 : 0);
        ramp(n.comp.threshold, speakerMode ? -26 : 0);
        ramp(n.comp.knee, speakerMode ? 10 : 0);
        ramp(n.comp.ratio, speakerMode ? 4 : 1);
    } else if (audio) {
        // Plain <audio> element: only 0-100% is possible (and all that's needed).
        audio.volume = Math.min(level, 1);
    }

    updateVolumeUI();
}

function updateVolumeUI() {
    const pctText = volumePct + '%';

    const button = document.getElementById('volumeButton');
    if (button) {
        let icon = 'high';
        if (volumePct === 0) icon = 'mute';
        else if (volumePct < 50) icon = 'low';
        button.dataset.vol = icon;
        button.classList.toggle('is-on', volumePct > 100 || speakerMode);
        button.classList.toggle('is-speaker', speakerMode);
        const label = button.querySelector('.act-label');
        if (label) label.textContent = speakerMode ? 'Speaker' : (volumePct === 100 ? 'Volume' : pctText);
        button.title = 'Volume: ' + pctText + (speakerMode ? ', Speaker mode on' : '');
    }

    const slider = document.getElementById('volumeSlider');
    if (slider) {
        if (parseInt(slider.value, 10) !== volumePct) slider.value = String(volumePct);
        const pos = (volumePct / VOLUME_MAX) * 100;
        slider.style.setProperty('--pct', pos + '%');
        slider.style.setProperty('--fill', Math.min(pos, 50) + '%');
        slider.setAttribute('aria-valuetext', pctText + (volumePct > 100 ? ' (boosted)' : ''));
        slider.classList.toggle('is-boosting', volumePct > 100);
    }

    const readout = document.getElementById('volumeReadout');
    if (readout) readout.textContent = pctText;

    const speakerSwitch = document.getElementById('speakerSwitch');
    if (speakerSwitch) speakerSwitch.setAttribute('aria-checked', String(speakerMode));

    const reset = document.getElementById('volumeReset');
    if (reset) reset.hidden = (volumePct === 100 && !speakerMode);
}

// Bookmarks dialog functions
async function openBookmarksDialog() {
    return openUserListDialog({
        mode: 'getBookmarks',
        title: 'My Bookmarks',
        emptyText: 'No bookmarks yet. Click the ☆ star icon on any audiobook to bookmark it.',
        itemKey: 'bookmarks'
    });
}

async function openRatingsDialog() {
    return openUserListDialog({
        mode: 'getRatings',
        title: 'My Ratings',
        emptyText: 'No ratings yet. Rate an audiobook to see it here.',
        itemKey: 'ratings'
    });
}

async function openUserListDialog(opts) {
    const modal = document.getElementById('bookmarksModal');
    const content = document.getElementById('bookmarksContent');

    // Reuse the same modal, but set title dynamically
    const titleEl = modal ? modal.querySelector('h3') : null;
    if (titleEl) titleEl.textContent = opts.title || 'My Items';

    content.innerHTML = '<p>Loading...</p>';
    modal.showModal();

    try {
        const r = await fetch('?mode=' + encodeURIComponent(opts.mode), { cache: 'no-store' });
        const data = await r.json();

        if (!data.ok) {
            content.innerHTML = '<p style="color:red;">Error: ' + escapeHtml(data.error || 'Failed to load') + '</p>';
            return;
        }

        const items = data[opts.itemKey] || [];
        if (!items.length) {
            content.innerHTML = '<p>' + (opts.emptyText || 'No items found.') + '</p>';
            return;
        }

        const isWideScreen = window.innerWidth > 1000;

        // Wide screens: DataTables
        if (isWideScreen) {
            // Build table structure for DataTables (reusing existing table id/styles)
            let html = '<table id="bookmarksTable" class="display" style="width:100%">';
            html += '<thead><tr>';
            html += '<th>Book Name</th>';
            html += '<th>Author</th>';
            html += '<th>Folder</th>';
            html += '<th>Rating</th>';
            html += '<th>My Rating</th>';
            html += '<th>Link</th>';
            html += '</tr></thead><tbody></tbody></table>';
            content.innerHTML = html;

            const tableData = items.map(b => {
                const ratingDisplay = b.rate !== null ? b.rate.toFixed(1) : '';
                const rateCountDisplay = b.rateCount !== null ? ' (' + b.rateCount.toLocaleString() + ')' : '';
                const myRatingHtml = b.myRating !== null ? ratingImgHtml(b.myRating) : '';
                const url = safeUrl(b.url);
                const urlHtml = url ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" title="Open external link">&#128279;</a>' : '';

                return [
                    bookmarkLinkHtml(b),
                    escapeHtml(b.author || ''),
                    escapeHtml(b.parentName || ''),
                    { display: escapeHtml(ratingDisplay + rateCountDisplay), sort: b.rate !== null ? b.rate : 0 },
                    { display: myRatingHtml, sort: b.myRating !== null ? b.myRating : 0 },
                    urlHtml
                ];
            });

            if (typeof $ !== 'undefined' && $.fn.DataTable) {
                $('#bookmarksTable').DataTable({
                    data: tableData,
                    pageLength: 25,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    order: [[0, 'asc']],
                    columnDefs: [
                        { orderable: false, targets: [5] },
                        {
                            targets: 3,
                            type: 'num',
                            render: function (data, type) {
                                if (type === 'sort' || type === 'type') return data.sort;
                                return data.display;
                            }
                        },
                        {
                            targets: 4,
                            type: 'num',
                            render: function (data, type) {
                                if (type === 'sort' || type === 'type') return data.sort;
                                return data.display;
                            }
                        }
                    ],
                    language: {
                        search: "Filter:",
                        lengthMenu: "Show _MENU_ entries"
                    }
                });

                // Delegate click to navigate
                $('#bookmarksTable tbody').off('click.userlist').on('click.userlist', '.bookmark-link', function (e) {
                    e.preventDefault();
                    const path = this.dataset.path;
                    if (path) {
                        closeBookmarksDialog();
                        loadFolder(path);
                    }
                });
            }

            return;
        }

        // Narrow screens: simple list (like renderContent's mobile view)
        let listHtml = '<ul class="userlist">';
        items.forEach(b => {
            const ratingDisplay = b.rate !== null ? b.rate.toFixed(1) : '';
            const rateCountDisplay = b.rateCount !== null ? ' (' + b.rateCount.toLocaleString() + ')' : '';
            const myRatingHtml = b.myRating !== null ? ratingImgHtml(b.myRating) : '';
            const author = b.author ? escapeHtml(b.author) : '';
            const parent = b.parentName ? escapeHtml(b.parentName) : '';
            const url = safeUrl(b.url);
            const urlHtml = url ? '<a class="AmazonLink" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" title="Open external link">&#128279;</a>' : '';

            listHtml += `
<li class="userlist-item">
  <div class="userlist-row">
    ${bookmarkLinkHtml(b)}
    <span class="userlist-link">${urlHtml}</span>
  </div>
  <div class="userlist-meta">
    ${author ? `<div><strong>Author:</strong> ${author}</div>` : ''}
    ${parent ? `<div><strong>Folder:</strong> ${parent}</div>` : ''}
    ${(ratingDisplay || rateCountDisplay) ? `<div><strong>Rating:</strong> ${escapeHtml(ratingDisplay)}${escapeHtml(rateCountDisplay)}</div>` : ''}
    ${myRatingHtml ? `<div><strong>My Rating:</strong> ${myRatingHtml}</div>` : ''}
  </div>
</li>`;
        });
        listHtml += '</ul>';
        content.innerHTML = listHtml;

        // Stop propagation for external links (matches main list behavior)
        content.querySelectorAll('a.AmazonLink').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.stopPropagation();
            });
        });

        // Click-to-navigate
        content.querySelectorAll('.bookmark-link').forEach(a => {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                const path = a.dataset.path;
                if (path) {
                    closeBookmarksDialog();
                    loadFolder(path);
                }
            });
        });
    } catch (e) {
        content.innerHTML = '<p style="color:red;">Error loading: ' + escapeHtml(e.message) + '</p>';
    }
}

// Book title in the Bookmarks/Ratings list. The server sends an empty folderPath when the
// folder no longer exists in the library, so show plain text instead of a dead link.
function bookmarkLinkHtml(b) {
    const name = escapeHtml(b.bookName || '');
    if (!b.folderPath) {
        return '<span class="bookmark-missing" title="Folder not found in the library">' + name + '</span>';
    }
    return '<a href="#" class="bookmark-link" data-path="' + escapeHtml(b.folderPath) + '">' + name + '</a>';
}

function closeBookmarksDialog() {
    // Destroy DataTable before closing to clean up
    if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#bookmarksTable')) {
        $('#bookmarksTable').DataTable().destroy();
    }
    document.getElementById('bookmarksModal').close();
}

// ---------------------------------------------------------------------------
// Library search: folder name, title, author, and average user rating
// (the average of every user's UserFolder.Rating for that book).
// ---------------------------------------------------------------------------
let searchRequestSeq = 0;

function openSearchDialog() {
    const modal = document.getElementById('searchModal');
    if (!modal) return;
    if (!modal.open) modal.showModal();
    const name = document.getElementById('searchName');
    if (name) { name.focus(); name.select(); }
}

// Results stay in place, so reopening the dialog shows the last search.
function closeSearchDialog() {
    document.getElementById('searchModal').close();
}

function destroySearchTable() {
    if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#searchTable')) {
        $('#searchTable').DataTable().destroy();
    }
}

function clearSearch() {
    searchRequestSeq++; // drop any search still in flight
    document.getElementById('searchForm').reset();
    destroySearchTable();
    document.getElementById('searchResults').innerHTML = '';
    document.getElementById('searchName').focus();
}

async function runSearch() {
    const name = document.getElementById('searchName').value.trim();
    const title = document.getElementById('searchTitle').value.trim();
    const author = document.getElementById('searchAuthor').value.trim();
    const minAvg = document.getElementById('searchMinAvg').value;
    const results = document.getElementById('searchResults');

    destroySearchTable();
    if (!name && !title && !author && !minAvg) {
        results.innerHTML = '<p class="search-note">Enter a folder name, a title or an author, or pick an average rating.</p>';
        return;
    }

    const seq = ++searchRequestSeq;
    results.innerHTML = '<p class="search-note">Searching…</p>';

    try {
        const params = new URLSearchParams({ mode: 'search', name, title, author, minAvg });
        const r = await fetch('?' + params.toString(), { cache: 'no-store' });
        const data = await r.json();
        if (seq !== searchRequestSeq) return; // a newer search replaced this one

        if (!data.ok) {
            results.innerHTML = '<p class="search-error">' + escapeHtml(data.error || 'Search failed.') + '</p>';
            return;
        }
        renderSearchResults(data.results || [], !!data.truncated);
    } catch (e) {
        if (seq !== searchRequestSeq) return;
        results.innerHTML = '<p class="search-error">Search failed: ' + escapeHtml(e.message) + '</p>';
    }
}

// Stars plus "4.3 (3)": the average and how many users rated it.
function avgRatingHtml(b) {
    if (b.avgRating === null) return '';
    const count = b.userRatingCount === 1 ? '1 rating' : b.userRatingCount + ' ratings';
    return ratingImgHtml(b.avgRating)
        + ' <span class="muted" title="' + escapeHtml(count) + '">' + b.avgRating.toFixed(1)
        + ' (' + b.userRatingCount + ')</span>';
}

function renderSearchResults(items, truncated) {
    const results = document.getElementById('searchResults');

    if (!items.length) {
        results.innerHTML = '<p class="search-note">No books match that search.</p>';
        return;
    }

    let summary = items.length.toLocaleString() + (items.length === 1 ? ' book found' : ' books found');
    if (truncated) summary += ' &mdash; showing the first ' + items.length.toLocaleString() + ', narrow the search to see the rest';
    const summaryHtml = '<p class="search-note">' + summary + '</p>';

    const publicRating = b => (b.rate !== null ? b.rate.toFixed(1) : '')
        + (b.rateCount !== null ? ' (' + b.rateCount.toLocaleString() + ')' : '');
    const linkHtml = b => {
        const url = safeUrl(b.url);
        return url ? '<a class="AmazonLink" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" title="Book page">&#128279;</a>' : '';
    };

    const hasDataTables = typeof $ !== 'undefined' && $.fn.DataTable;
    if (window.innerWidth > 1000 && hasDataTables) {
        results.innerHTML = summaryHtml
            + '<table id="searchTable" class="display" style="width:100%"><thead><tr>'
            + '<th>Book Name</th><th>Author</th><th>Folder</th><th>Avg user rating</th>'
            + '<th>My Rating</th><th>Public rating</th><th>Link</th>'
            + '</tr></thead><tbody></tbody></table>';

        const tableData = items.map(b => [
            bookmarkLinkHtml(b),
            escapeHtml(b.author || ''),
            escapeHtml(b.parentName || ''),
            { display: avgRatingHtml(b), sort: b.avgRating !== null ? b.avgRating : -1 },
            { display: b.myRating !== null ? ratingImgHtml(b.myRating) : '', sort: b.myRating !== null ? b.myRating : -1 },
            { display: escapeHtml(publicRating(b)), sort: b.rate !== null ? b.rate : -1 },
            linkHtml(b)
        ]);

        const sortable = {
            type: 'num',
            render: function (data, type) {
                return (type === 'sort' || type === 'type') ? data.sort : data.display;
            }
        };

        $('#searchTable').DataTable({
            data: tableData,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            order: [], // keep the server's order: best average rating first
            columnDefs: [
                { orderable: false, targets: [6] },
                Object.assign({ targets: [3, 4, 5] }, sortable)
            ],
            language: { search: "Filter:", lengthMenu: "Show _MENU_ entries" }
        });
    } else {
        let html = summaryHtml + '<ul class="userlist">';
        items.forEach(b => {
            const pr = publicRating(b);
            const avg = avgRatingHtml(b);
            html += `
<li class="userlist-item">
  <div class="userlist-row">
    ${bookmarkLinkHtml(b)}
    <span class="userlist-link">${linkHtml(b)}</span>
  </div>
  <div class="userlist-meta">
    ${b.author ? `<div><strong>Author:</strong> ${escapeHtml(b.author)}</div>` : ''}
    ${b.parentName ? `<div><strong>Folder:</strong> ${escapeHtml(b.parentName)}</div>` : ''}
    ${avg ? `<div><strong>Avg user rating:</strong> ${avg}</div>` : ''}
    ${b.myRating !== null ? `<div><strong>My Rating:</strong> ${ratingImgHtml(b.myRating)}</div>` : ''}
    ${pr ? `<div><strong>Public rating:</strong> ${escapeHtml(pr)}</div>` : ''}
  </div>
</li>`;
        });
        results.innerHTML = html + '</ul>';
    }

    // One handler for both layouts (DataTables redraws rows when paging/sorting).
    results.onclick = function (e) {
        const a = e.target.closest('.bookmark-link');
        if (!a || !results.contains(a)) return;
        e.preventDefault();
        const path = a.dataset.path;
        if (path) {
            closeSearchDialog();
            loadFolder(path);
        }
    };
}

// Escapes all five HTML-significant characters, so the result is safe both as
// element text and inside quoted attributes. (The old textContent/innerHTML trick
// did not escape quotes.)
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

// Only allow http(s) links; blocks javascript: and data: URLs stored in the database.
function safeUrl(u) {
    if (!u) return '';
    try {
        const x = new URL(String(u), window.location.href);
        return (x.protocol === 'http:' || x.protocol === 'https:') ? x.href : '';
    } catch (e) {
        return '';
    }
}


// ---------------------------------------------------------------------------
// Player UI: play/pause buttons, seek bar, chapter title, speed, mini player,
// and lock-screen / headphone controls (Media Session).
// ---------------------------------------------------------------------------

const SPEEDS = [1, 1.25, 1.5, 1.75, 2, 0.75];
let speedIndex = 0;
let isSeeking = false;
let transportInView = true;
let playingBook = { title: '', author: '' };
let lastMediaKey = '';

function initPlayerUI(audio) {
    // Play / pause (main + mini)
    ['playPauseButton', 'miniPlayButton'].forEach(id => {
        const b = document.getElementById(id);
        if (b) b.addEventListener('click', togglePlay);
    });
    ['play', 'pause', 'ended', 'loadedmetadata', 'canplay'].forEach(ev =>
        audio.addEventListener(ev, syncPlayPauseUI));

    // Previous / next chapter
    const prev = document.getElementById('prevTrackButton');
    const next = document.getElementById('nextTrackButton');
    if (prev) prev.addEventListener('click', () => previousChapter());
    if (next) next.addEventListener('click', () => goToTrack(1));

    // Seek bar: preview while dragging, seek on release
    const seekBar = document.getElementById('seekBar');
    if (seekBar) {
        seekBar.addEventListener('input', () => {
            isSeeking = true;
            const dur = audioDuration(audio);
            const t = dur * (seekBar.value / 1000);
            seekBar.style.setProperty('--pct', (seekBar.value / 10) + '%');
            setText('timeElapsed', formatTime(t));
            setText('timeRemaining', dur ? '-' + formatTime(dur - t) : '');
        });
        seekBar.addEventListener('change', () => {
            const dur = audioDuration(audio);
            if (dur) audio.currentTime = dur * (seekBar.value / 1000);
            isSeeking = false;
            updateTimes();
        });
    }
    ['timeupdate', 'loadedmetadata', 'durationchange', 'emptied', 'seeked'].forEach(ev =>
        audio.addEventListener(ev, updateTimes));

    // Playback speed (remembered on this device)
    try {
        const saved = parseFloat(localStorage.getItem('playbackSpeed'));
        const idx = SPEEDS.indexOf(saved);
        if (idx >= 0) speedIndex = idx;
    } catch (e) { /* ignore */ }
    const speedButton = document.getElementById('speedButton');
    if (speedButton) {
        speedButton.addEventListener('click', () => {
            speedIndex = (speedIndex + 1) % SPEEDS.length;
            try { localStorage.setItem('playbackSpeed', String(SPEEDS[speedIndex])); } catch (e) { /* ignore */ }
            applySpeed();
        });
    }
    audio.addEventListener('loadedmetadata', applySpeed);
    applySpeed();

    // Mini player: appears when the main controls scroll out of view
    const transport = document.querySelector('#playerControl .transport');
    if (transport && 'IntersectionObserver' in window) {
        new IntersectionObserver(entries => {
            transportInView = entries[entries.length - 1].isIntersecting;
            updateMiniVisibility();
        }).observe(transport);
    }
    const miniInfo = document.getElementById('miniInfo');
    if (miniInfo) {
        miniInfo.addEventListener('click', () => {
            const player = document.getElementById('playerControl');
            if (player) player.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    initMediaSession(audio);
    syncPlayPauseUI();
    updateTimes();
}

function togglePlay() {
    const audio = document.getElementById('audioPlayer');
    if (!audio || !audio.src) return;
    if (audio.paused || audio.ended) {
        playAudio(audio);
    } else {
        audio.pause();
    }
    syncPlayPauseUI();
}

function syncPlayPauseUI() {
    const audio = document.getElementById('audioPlayer');
    const isPlaying = !!audio && audio.readyState >= 1 && !audio.paused && !audio.ended;
    setPlayButtonsState(isPlaying);
}

function setPlayButtonsState(isPlaying) {
    ['playPauseButton', 'miniPlayButton'].forEach(id => {
        const b = document.getElementById(id);
        if (!b) return;
        const label = isPlaying ? 'Pause' : 'Play';
        b.dataset.state = isPlaying ? 'playing' : 'paused';
        b.setAttribute('aria-label', label);
        b.title = label;
    });
    if ('mediaSession' in navigator) {
        try { navigator.mediaSession.playbackState = isPlaying ? 'playing' : 'paused'; } catch (e) { /* ignore */ }
    }
}

// Previous: restart the chapter if we're more than 5 s in, otherwise go back one.
function previousChapter() {
    const audio = document.getElementById('audioPlayer');
    if (audio && audio.currentTime > 5) {
        audio.currentTime = 0;
        return;
    }
    goToTrack(-1);
}

function goToTrack(delta) {
    const audio = document.getElementById('audioPlayer');
    const selector = document.getElementById('trackSelector');
    const i = selector.selectedIndex + delta;
    if (i < 0 || i >= selector.options.length) return;
    selector.selectedIndex = i;
    pendingResumeFolder = null;
    setAudioFileAndPlay(audio, selector.value);
    if (playingFolderPath) setServerProgress(playingFolderPath, 0, selector.value);
}

function audioDuration(audio) {
    return audio && Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0;
}

function updateTimes() {
    const audio = document.getElementById('audioPlayer');
    if (!audio) return;
    const dur = audioDuration(audio);
    const cur = audio.currentTime || 0;
    const pct = dur ? Math.min(100, (cur / dur) * 100) : 0;

    const seekBar = document.getElementById('seekBar');
    if (seekBar && !isSeeking) {
        seekBar.value = String(Math.round(pct * 10));
        seekBar.style.setProperty('--pct', pct + '%');
        seekBar.disabled = !dur;
        setText('timeElapsed', formatTime(cur));
        setText('timeRemaining', dur ? '-' + formatTime(dur - cur) : '');
    }

    const mini = document.getElementById('miniProgress');
    if (mini) mini.style.width = pct + '%';

    if (dur && 'mediaSession' in navigator && navigator.mediaSession.setPositionState) {
        try {
            navigator.mediaSession.setPositionState({
                duration: dur,
                playbackRate: audio.playbackRate || 1,
                position: Math.min(cur, dur)
            });
        } catch (e) { /* ignore */ }
    }
}

function updateNowPlaying() {
    const selector = document.getElementById('trackSelector');
    if (!selector) return;
    const idx = selector.selectedIndex;
    const total = selector.options.length;
    const title = idx >= 0 ? selector.options[idx].textContent : '';

    setText('npIndex', total ? `Chapter ${idx + 1} of ${total}` : '');
    setText('npTitle', title);
    setText('miniTitle', title);
    setText('miniSub', playingBook.title);

    const prev = document.getElementById('prevTrackButton');
    const next = document.getElementById('nextTrackButton');
    if (prev) prev.disabled = idx < 0;
    if (next) next.disabled = idx < 0 || idx >= total - 1;

    updateMiniVisibility();
    updateMediaMetadata(title);
}

function updateMiniVisibility() {
    const mini = document.getElementById('miniPlayer');
    const player = document.getElementById('playerControl');
    const audio = document.getElementById('audioPlayer');
    if (!mini || !player || !audio) return;
    const onPlayerView = player.style.display !== 'none' && !!audio.getAttribute('src');
    const show = onPlayerView && !transportInView;
    if (mini.hidden === show) mini.hidden = !show;
    document.body.classList.toggle('has-mini', show);
}

function applySpeed() {
    const audio = document.getElementById('audioPlayer');
    const rate = SPEEDS[speedIndex] || 1;
    if (audio) {
        audio.defaultPlaybackRate = rate;
        audio.playbackRate = rate;
    }
    const b = document.getElementById('speedButton');
    if (b) {
        const v = b.querySelector('.speed-value');
        if (v) v.textContent = String(rate) + '×';
        b.classList.toggle('is-on', rate !== 1);
        b.title = 'Playback speed: ' + rate + '×';
    }
}

// Lock screen, headphones, car controls
function initMediaSession(audio) {
    if (!('mediaSession' in navigator)) return;
    const handlers = {
        play: () => playAudio(audio),
        pause: () => audio.pause(),
        seekbackward: d => goBackSec((d && d.seekOffset) || 30),
        seekforward: d => goForwardSec((d && d.seekOffset) || 30),
        previoustrack: () => previousChapter(),
        nexttrack: () => goToTrack(1),
        seekto: d => { if (d && typeof d.seekTime === 'number') audio.currentTime = d.seekTime; }
    };
    Object.keys(handlers).forEach(action => {
        try { navigator.mediaSession.setActionHandler(action, handlers[action]); } catch (e) { /* unsupported action */ }
    });
}

function updateMediaMetadata(chapterTitle) {
    if (!('mediaSession' in navigator) || typeof MediaMetadata === 'undefined') return;
    const key = chapterTitle + '|' + playingBook.title;
    if (!chapterTitle || key === lastMediaKey) return;
    lastMediaKey = key;
    try {
        navigator.mediaSession.metadata = new MediaMetadata({
            title: chapterTitle,
            artist: playingBook.author,
            album: playingBook.title,
            artwork: [{ src: 'images/icon192.png', sizes: '192x192', type: 'image/png' }]
        });
    } catch (e) { /* ignore */ }
}

// "03. The Debate Over Evolution.mp3" -> { num: "03", title: "The Debate Over Evolution", ext: "mp3" }
function chapterParts(fileName, index) {
    const name = String(fileName).split('/').pop();
    const extMatch = name.match(/\.([a-z0-9]{2,4})$/i);
    const ext = extMatch ? extMatch[1] : '';
    const base = extMatch ? name.slice(0, -extMatch[0].length) : name;
    const m = base.match(/^(\d{1,4})\s*[.)_-]?\s+(.+)$/) || base.match(/^(\d{1,4})[._-](.+)$/);
    if (m) return { num: m[1].padStart(2, '0'), title: m[2].trim(), ext: ext };
    return { num: String(index + 1).padStart(2, '0'), title: base, ext: ext };
}

function formatTime(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = String(sec % 60).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

function setText(id, text) {
    const el = document.getElementById(id);
    if (el && el.textContent !== text) el.textContent = text;
}
