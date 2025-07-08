let basePath = '';
let sFolderSep = '\\';
let sCurrentFolder = "";
let currentData = null;

// Global flag to track dialog source
let editDialogOpenedFromTable = false;

function getBasePathAndStart() {

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js').then(() => {
            console.log('Service Worker registered');
        }).catch(console.error);
    }

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
            const savedPath = folder_param || localStorage.getItem('lastFolderPath') || basePath;
            loadFolder(savedPath);
        })
        .catch(err => showError(err.message));
}

document.addEventListener('DOMContentLoaded', function(){
    getBasePathAndStart();
    setupShareLink();
});

function setupShareLink() {
    const shareLink = document.getElementById('shareLink');
    if (shareLink) {
        shareLink.addEventListener('click', function(e) {

            var o = document.querySelector('span.share-link-feedback');
            if (o == null) return;

            e.preventDefault();
            o.style.display = "";
            setTimeout(() => {
                        if (o) o.style.display = "none";
            }, 1500);

            const url = shareLink.href;

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
        });
    }
}

// Handle window resize for responsive DataTable behavior
window.addEventListener('resize', function() {
    if (currentData && currentData.Subfolders.length > 0) {
        const wasWideScreen = document.getElementById('subfoldersTable') !== null;
        const isWideScreen = window.innerWidth > 1000;
        
        // Only re-render if the display mode should change
        if (wasWideScreen !== isWideScreen) {
            renderContent(currentData);
        }
    }
});

function loadFolder(path) {
    localStorage.setItem('lastFolderPath', path);
    ShowSpinner();

    document.getElementById('shareLink').href = '?folder=' + encodeURIComponent(path);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '?mode=json', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            HideSpinner();
            if (xhr.status === 200) {
                try {
                    if (xhr.responseText==""){
                        localStorage.removeItem('lastFolderPath');
                        showError('Empty text for: ' + path);
                    } else {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) throw new Error(data.error);
                        currentData = data; // Store data for resize handler
                        renderBreadcrumb(data);
                        renderRatings(data);
                        renderContent(data);

                        // Update editFolderModal inputs
                        if (document.getElementById('editFolderModal')) {
                            document.getElementById('editFolderPath').value = data.CurrentPath || '';
                            document.getElementById('editTitle').value = data.Title || '';
                            document.getElementById('editTitleUrl').value = data.TitleUrl || '';                         
                            document.getElementById('editMyRating').value = GetMyRating(data);
                        }                        
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

function renderRatings(data) {
    if (data.Title == "") {
        document.getElementById('ratings').innerHTML = "";
        return;
    }

    var html = "";

    if (data.TitleRating !== "") {
        html += " [" + data.TitleRating;

        if (data.RateCount !== "") {
            html += " - " + data.RateCount;
        }

        html += "] ";
    }

    if (data.TitleUrl == "") {
        html += data.Title;
    } else {
        html += "<a href='" + data.TitleUrl + "' target='_blank'>" + data.Title + "</a>";
    }

    if (data.MyRating !== "") {
        html += " <img class='imgRate' src='" + GetRatingImg(data.MyRating) + "'>";
    }

    document.getElementById('ratings').innerHTML = html;
}

function GetRatingImg(i) {
    var w = parseInt(i);
    var d = (parseFloat(i) - w) == 0 ? "0": "5";
    return "images/stars-" + w + "-" + d + ".gif"
}

function renderBreadcrumb(data) {
    var relative = data.CurrentPath.replace(basePath, '');

    //removes a leading backslash or forward slash
    if (sFolderSep == "/") {
        relative = relative.replace(/^\//, '');
    } else {
        relative = relative.replace(/^\\/, '');
    }

    const parts = relative ? relative.split(sFolderSep) : [];
    let html = '<span class="crumb" data-path="' + basePath + '">Root</span>';
    let accumulated = basePath;

    for (let i = 0; i < parts.length; i++) {
        accumulated += sFolderSep + parts[i];
        html += ' / <span class="crumb" data-path="' + accumulated + '">' + parts[i] + '</span>';
    }

    document.getElementById('breadcrumb').innerHTML = html;
}

function renderContent(data) {
    const container = document.getElementById('content');
    let html = '';
    const selector = document.getElementById("trackSelector");
    const playerControl = document.getElementById("playerControl");
    const shareDiv = document.getElementById("shareDiv");
    selector.length = 0;

    // Clean up existing DataTable if it exists
    if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#subfoldersTable')) {
        $('#subfoldersTable').DataTable().destroy();
    }

    if (data.Subfolders.length > 0) {
        const isWideScreen = window.innerWidth > 1000;  
        
        if (isWideScreen) {
            // Use DataTables for wide screens
            const tableData = [];
            data.Subfolders.forEach(o => {
                const f = o.Folder;
                var name = f.split(sFolderSep).pop();
                if (name != "images" && name != "bin" && name != "obj" && name != ".vs") {
                    let displayName = name;
                    let titleUrl = '';
                    let rating = '';
                    let rateCount = o.RateCount || '';
                    let myRating = '';
                    let author = '';
                    let category = '';
                    let pubYear = '';

                    if (o.Title !== "") {
                        displayName = o.Title;
                    }

                    if (o.TitleUrl != "") {
                        titleUrl = "<a href='" + o.TitleUrl + "' target='_blank' class='AmazonLink'>&#128279;</a>";
                    }

                    if (o.TitleRating !== "") {
                        rating = o.TitleRating;
                    }

                    if (o.MyRating != "") {
                        myRating = "<img class='imgRate' src='" + GetRatingImg(o.MyRating) + "'>";
                    }

                    if (o.Author !== "") {
                        author = o.Author;
                    }

                    if (o.Category !== "") {
                        category = o.Category;
                    }

                    if (o.PubYear !== "") {
                        pubYear = o.PubYear;
                    }

                    // Format rate count with thousands separator while preserving numeric value
                    let formattedRateCount = rateCount;
                    if (rateCount && !isNaN(rateCount)) {
                        formattedRateCount = parseInt(rateCount).toLocaleString();
                    }

                    // Add Edit button (new column)
                    let editBtn = `<button class="edit-folder-btn" data-path="${f}">Edit</button>`;

                    tableData.push([
                        `<span class="folder" data-path="${f}">${displayName}</span>`,
                        rating,
                        formattedRateCount,
                        myRating,
                        author,
                        category,
                        pubYear,
                        editBtn, // moved Edit before Link
                        titleUrl // moved Link to last column
                    ]);
                }
            });

            html = `<table id="subfoldersTable" class="display" style="width:100%">
    <thead>
        <tr>
            <th>Folder Name</th>
            <th>Rating</th>
            <th>Rate Count</th>
            <th>My Rating</th>
            <th>Author</th>
            <th>Category</th>
            <th>Year</th>
            <th>Edit</th>
            <th>Link</th>
        </tr>
    </thead>
    <tbody></tbody>
</table>`;
            
            container.innerHTML = html;
            
            // Initialize DataTable when jQuery and DataTables are available
            if (typeof $ !== 'undefined' && $.fn.DataTable) {
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
                            targets: 2, // Rate Count column (now index 2)
                            type: 'num',
                            render: function(data, type, row) {
                                if (type === 'sort' || type === 'type') {
                                    // For sorting, return the numeric value
                                    return data ? parseInt(data.replace(/,/g, '')) || 0 : 0;
                                }
                                // For display, return the formatted string
                                return data;
                            }
                        },
                        { 
                            targets: 3, // My Rating column (now index 3)
                            type: 'num',
                            render: function(data, type, row) {
                                if (type === 'sort' || type === 'type') {
                                    // For sorting, extract numeric value from image src
                                    if (data && data.includes('stars-')) {
                                        const match = data.match(/stars-(\d+)-([05])/);
                                        if (match) {
                                            const whole = parseInt(match[1]);
                                            const decimal = match[2] === '5' ? 0.5 : 0;
                                            return whole + decimal;
                                        }
                                    }
                                    return 0; // No rating
                                }
                                // For display, return the HTML with image
                                return data;
                            }
                        },
                        { 
                            targets: 8, // Link column (now index 8)
                            render: function(data, type, row) {
                                if (type === 'sort' || type === 'type') {
                                    // Extract URL from anchor tag for sorting
                                    if (data && data.includes('href=')) {
                                        const match = data.match(/href=['\"]([^'\"]*)['\"]/);
                                        if (match) return match[1];
                                    }
                                    return '';
                                }
                                return data;
                            }
                        },
                        { width: "20%", targets: 0 }, // Folder name column width
                        { width: "8%", targets: 1 }, // Rating column width
                        { width: "8%", targets: 2 }, // Rate Count column width
                        { width: "8%", targets: 3 }, // My Rating column width
                        { width: "20%", targets: 4 }, // Author column width
                        { width: "15%", targets: 5 }, // Category column width
                        { width: "7%", targets: 6 }, // Year column width
                        { width: "5%", targets: 7 }, // Edit column width
                        { width: "5%", targets: 8 } // Link column width
                    ]
                });

                // Add event handler for Edit buttons
                $('#subfoldersTable tbody').on('click', '.edit-folder-btn', function(e) {
                    e.preventDefault();
                    const path = this.dataset.path;
                    if (path) {
                        fetchFolderDataForEdit(path);
                    }
                });
            } else {
                // Fallback to regular list if DataTables is not available
                console.warn('DataTables not available, falling back to list view');
                html = '';
                data.Subfolders.forEach(o => {
                    const f = o.Folder;
                    var name = f.split(sFolderSep).pop();
                    if (name != "images" && name != "bin" && name != "obj" && name != ".vs") {
                        if (o.Title !== "") name = o.Title;
                        if (o.TitleUrl != "") name += " <a href='" + o.TitleUrl + "' target='_blank' class='AmazonLink'>&#128279;</a>";
                        if (o.TitleRating !== "") {
                            name += " [" + o.TitleRating;
                            if (o.RateCount !== "") name += " - " + o.RateCount;
                            name += "]";
                        }
                        if (o.MyRating != "") name += " <img class='imgRate' src='" + GetRatingImg(o.MyRating) + "'>";
                        html += '<li class="folder" data-path="' + f + '">' + name + '</li>';
                    }
                });
                container.innerHTML = "<ul>" + html + "</ul>";
            }
        } else {
            // Use traditional list for narrow screens
            html += '';
            data.Subfolders.forEach(o => {
                const f = o.Folder;
                var name = f.split(sFolderSep).pop();
                if (name != "images" && name != "bin" && name != "obj" && name != ".vs") {

                    if (o.Title !== "") {
                        name = o.Title;
                    }

                    if (o.TitleUrl != "") {
                        name += " <a href='" + o.TitleUrl + "' target='_blank' class='AmazonLink'>&#128279;</a>";
                    }

                    if (o.TitleRating !== "") {
                        name += " [" + o.TitleRating;

                        if (o.RateCount !== "") {
                            name += " - " + o.RateCount;
                        }

                        name += "]";
                    }

                    if (o.MyRating != "") {
                        name += " <img class='imgRate' src='" + GetRatingImg(o.MyRating) + "'>";
                    }

                    html += '<li class="folder" data-path="' + f + '">' + name + '</li>';
                }            
            });
            
            container.innerHTML = "<ul>" + html + "</ul>";
        }

        playerControl.style.display = "none";
        shareDiv.style.display = "none";

    } else if (data.Mp3Files.length > 0) {

        playerControl.style.display = "";
        shareDiv.style.display = "";

        html += '';
        sCurrentFolder = data.CurrentFolder;

        data.Mp3Files.forEach((f, index) => {
            const name = f.split(sFolderSep).pop();
            if (sCurrentFolder !== "") {
                f = sCurrentFolder + "/" + f;
            }

            html += `<li class="file" data-path="${f}" data-index="${index}"><div class="file-inner"><span class="downloaded"></span>${name}</div></li>`;

            if (f.toLowerCase().endsWith(".mp3")) {
                const option = document.createElement("option");
                option.value = f;
                option.textContent = name;
                selector.appendChild(option);
            }
        });

        loadTracks();

        container.innerHTML = "<ul>" + html + "</ul>";
        checkFolderCache();
    } else {
        container.innerHTML = '<h2>No subfolders or MP3 files found.</h2>';
    }    

    // Add event listeners for Amazon links to prevent propagation
    document.querySelectorAll('a.AmazonLink').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.stopPropagation();  
        });
    });

    // Add event listeners for DataTable folder clicks if using DataTable
    if (typeof $ !== 'undefined' && document.getElementById('subfoldersTable')) {
        $('#subfoldersTable tbody').on('click', '.folder', function(e) {
            e.preventDefault();
            const path = this.dataset.path;
            if (path) {
                loadFolder(path);
            }
        });
    }
}

function showError(msg) {
    HideSpinner();
    document.getElementById('content').innerHTML =
        '<div style="color:red;"><strong>Error:</strong> ' + msg + '</div>';
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
                setAudioFileAndPlay(audio, selector.value)
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }
    }
});

function goUpOneLevel() {
    const crumbs = document.getElementById('breadcrumb').getElementsByClassName("crumb");
    if (crumbs.length < 2) return;
    const path = crumbs[crumbs.length - 2].dataset.path;
    loadFolder(path);
}

function loadTracks() {
    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");
    const storageKey = encodeURIComponent(sCurrentFolder || basePath);
    const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');

    if (saved.url) {
        selector.value = saved.url;
        if (selector.selectedIndex === -1 && selector.length > 0) {
            selector.selectedIndex = 0;
            setAudioFileAndPlay(audio, selector.value);
        } else {
            setAudioFileAndPlay(audio, saved.url);
        }

        const setStartTime = () => {
            if (saved.time) {
                audio.currentTime = saved.time;
            }
            audio.removeEventListener('play', setStartTime);
        };

        audio.addEventListener('play', setStartTime);
    } else if (selector.length > 0) {
        selector.selectedIndex = 0;
        setAudioFileAndPlay(audio, selector.value);
    }

    selector.addEventListener("change", () => {
        setAudioFileAndPlay(audio, selector.value);
    });

    // Save progress every 5 seconds
    setInterval(() => {
        if (!audio.src || audio.paused) return;
        const sUrl = selector.value;
        const sCurrentTime = audio.currentTime;
        if (sUrl && sCurrentTime !== undefined) {
            localStorage.setItem(storageKey, JSON.stringify({ url: sUrl, time: sCurrentTime }));
        }
    }, 5000);

    var startTime = 0;

    // Auto-play next track
    audio.addEventListener("ended", () => {

        let endTime = performance.now();
        if (startTime > 0 && endTime - startTime < 1000) return;
        startTime = endTime;

        const currentIndex = selector.selectedIndex;
        if (currentIndex >= 0 && currentIndex < selector.options.length - 1) {
            const nextOption = selector.options[currentIndex + 1];
            selector.value = nextOption.value;
            setAudioFileAndPlay(audio, nextOption.value);
        }
    });

    // Update highlights/progress bar every 500ms
    setInterval(updateHighlights, 500);
}

function updateHighlights() {
    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");
    const currentPath = selector.value;
    const fileElements = document.querySelectorAll("#content .file");

    let currentIndex = -1;
    fileElements.forEach((el, i) => {
        const filePath = el.dataset.path;
        if (filePath === currentPath) {
            currentIndex = i;
        }

        el.classList.remove("highlighted", "current");
        const inner = el.querySelector('.file-inner');
        if (inner) inner.style.background = '';
    });

    fileElements.forEach((el, i) => {
        if (i < currentIndex) {
            el.classList.add("highlighted");
        } else if (i === currentIndex) {
            el.classList.add("current");
        }
    });

    if (currentIndex !== -1) {
        const currentEl = fileElements[currentIndex];
        const inner = currentEl.querySelector('.file-inner');
        if (inner && audio.duration > 0) {
            const percent = (audio.currentTime / audio.duration) * 100;
            inner.style.background = `linear-gradient(to right, #add8e6 ${percent}%, transparent ${percent}%)`;
        }
    }
}

function goBack30() {
    const audio = document.getElementById("audioPlayer");
    const selector = document.getElementById("trackSelector");

    if (audio.currentTime > 30) {
        audio.currentTime -= 30;
    } else {
        const currentIndex = selector.selectedIndex;
        if (currentIndex > 1) {
            const prevOption = selector.options[currentIndex - 1];
            const offset = 30 - audio.currentTime;
            selector.value = prevOption.value;
            setAudioFile(audio, prevOption.value)

            audio.addEventListener('loadedmetadata', function handler() {
                const seekTime = Math.max(0, audio.duration - offset);
                audio.currentTime = seekTime;
                audio.play();
                audio.removeEventListener('loadedmetadata', handler);
            });
        } else {
            audio.currentTime = 0;
        }
    }
}

function ShowSpinner() {
    document.getElementById('spinnerContainer').style.display = "";
    const audio = document.getElementById("audioPlayer");
    audio.pause()
}

function HideSpinner() {
    document.getElementById('spinnerContainer').style.display = "none";
}

function OpenFolderDialog(bTable, data) {
    editDialogOpenedFromTable = bTable;
    const folderData = data || currentData;
    if (folderData) {
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
    document.getElementById("editFolderModal").showModal();
}

function CloseFolderDialog() {
    document.getElementById("editFolderModal").close();
}

function SaveFolderDialog() {
    const data = new URLSearchParams();
    data.append('folderPath', document.getElementById('editFolderPath').value);
    data.append('title', document.getElementById('editTitle').value);
    data.append('titleUrl', document.getElementById('editTitleUrl').value);
    data.append('myRating', document.getElementById('editMyRating').value);
    data.append('rate', document.getElementById('editRate').value);
    data.append('rateCount', document.getElementById('editRateCount').value);
    data.append('author', document.getElementById('editAuthor').value);
    data.append('category', document.getElementById('editCategory').value);
    data.append('publicationDate', document.getElementById('editPublicationDate').value);

    ShowSpinner();

    fetch('?mode=updateFolder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
    })
        .then(r => { HideSpinner(); return r.json(); })
        .then(resp => {
            if (resp.success) {
                CloseFolderDialog();
                const currentPath = currentData ? currentData.CurrentPath : basePath;
                // Always refresh folder list after edit
                loadFolder(currentPath);
            } else {
                alert(resp.error || 'Update failed.');
            }
        })
        .catch(err => {
            HideSpinner();
            alert(err.message);
        });
}

// Fetch folder data for editing (does not re-render the view)
function fetchFolderDataForEdit(path) {
    editDialogOpenedFromTable = true;
    ShowSpinner();
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

function setAudioFileAndPlay(audio, sFile) {
    setAudioFile(audio, sFile).then(() => {
        try {
            audio.play();
        } catch (ex) {
            console.error('Error playing audio file:', err);
        }
    }).catch(err => {
        console.error('Error setting audio file:', err);
    })
}

async function setAudioFile(audio, sFile) {
    const cache = await caches.open('mp3-cache');
    const response = await cache.match(sFile);
    if (!response) {
        audio.src = sFile;
        return;
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    audio.src = url;
    audio.onended = () => URL.revokeObjectURL(url);
}

async function cacheFolder() {
    const selector = document.getElementById("trackSelector");
    for (var i = 0; i < selector.options.length; i++) {
        var sFile = selector.options[i].value;
        await downloadAndCache(sFile, i);
    }
}

async function downloadAndCache(sFile, i) {
    const cache = await caches.open('mp3-cache');
    const match = await cache.match(sFile);
    if (match) {
        setCacheIcon(i, true)
        return;
    }

    const response = await fetch(sFile);

    if (!response.ok) {
        setCacheIcon(i, false)
        return;
    }

    await cache.put(sFile, response.clone());
    setCacheIcon(i, true)
}


async function checkFolderCache() {
    const selector = document.getElementById("trackSelector");
    for (var i = 0; i < selector.options.length; i++) {
        var sFile = selector.options[i].value;
        await checkCache(sFile, i);
    }
}

async function checkCache(sFile, i) {
    const cache = await caches.open('mp3-cache');
    const match = await cache.match(sFile);
    setCacheIcon(i, !! match);
}

function setCacheIcon(i, match) {
    const span = document.querySelector(`li.file[data-index="${i}"] span.downloaded`);
    if (match) {
        span.innerHTML = "&#x1F5F8; "; //Check Mark
    } else {
        span.innerHTML = "&#x25CB; "; //White Circle
    }
}