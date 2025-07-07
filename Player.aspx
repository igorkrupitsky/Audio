<%@ Page Language="vb" CodeFile="Player.aspx.vb" Inherits="Player" %>
<!DOCTYPE html>
<html>
<head>
    <title>MP3 Player</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="Player.js?v=14"></script>
    <link href="Player.css?v=12" rel="stylesheet" />
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1976d2">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="images/icon192.png">
</head>
<body>
    <form id="form1">
        <div id="breadcrumb" class="breadcrumb"></div>
        
        <div id="shareDiv" style="float: right" title="Share Link">
            <a target="_blank" id="shareLink">&#128279;</a>
            <span id="editButton" title="Edit" onclick="OpenFolderDialog(false)">&#x270E;</span>
        </div>
        
        <div id="ratings"></div>

        <div id="playerControl" style="display:none">
            <select id="trackSelector"></select>
            <audio id="audioPlayer" controls></audio>
            <div class="controls">
                <a onclick="goBack30()" class="player-button"> Go Back 30 seconds</a>
                <a onclick="goUpOneLevel()" class="player-button"> Go Back</a>
                <a onclick="cacheFolder()" class="player-button"> Cache Folder</a>
            </div>
        </div>

        <div id="content"></div>
    </form>

    <div id="spinnerContainer" class="spinner-container"><div class="spinner"></div></div>


    <dialog id="editFolderModal" style="width: 800px; position: relative; max-height: 80vh; overflow-y: auto;">
        <div style="margin-right: 25px;">
            <span onclick="CloseFolderDialog()" style="position: absolute; top: 10px; right: 30px; font-size: 22px; font-weight: bold; color: #888; cursor: pointer; z-index: 10;" title="Close">&times;</span>
            <h3 id="editModalHeader">Edit Folder Info</h3>
            <form id="editFolderForm">
                <input type="hidden" id="editFolderPath" name="folderPath">
                <div style="margin-bottom: 12px;">
                    <label>Title:<br>
                    <input type="text" id="editTitle" name="title" style="width:100%; padding: 6px;"></label>
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Title URL:<br>
                    <input type="text" id="editTitleUrl" name="titleUrl" style="width:100%; padding: 6px;"></label>
                </div>
                <table style="width: 100%; margin-top: 15px;">
                    <tr>
                        <td style="width: 50%; padding-right: 10px; vertical-align: top;">
                            <label>My Rating:<br>
                            <select id="editMyRating" name="myRating" style="width:100%; padding: 6px;">
                                <option value=""></option>    
                                <option value="5.0">5.0</option>
                                <option value="4.5">4.5</option>
                                <option value="4.0">4.0</option>
                                <option value="3.5">3.5</option>
                                <option value="3.0">3.0</option>
                                <option value="2.5">2.5</option>
                                <option value="2.0">2.0</option>
                                <option value="1.5">1.5</option>
                                <option value="1.0">1.0</option>
                            </select></label>
                        </td>
                        <td style="width: 50%; padding-left: 10px; vertical-align: top;">
                            <label>Author:<br>
                            <input type="text" id="editAuthor" name="author" style="width:100%; padding: 6px;"></label>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-right: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Public Rating:<br>
                            <input type="number" id="editRate" name="rate" step="0.1" min="0" max="5" style="width:100%; padding: 6px;"></label>
                        </td>
                        <td style="padding-left: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Category:<br>
                            <input type="text" id="editCategory" name="category" style="width:100%; padding: 6px;"></label>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-right: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Rate Count:<br>
                            <input type="number" id="editRateCount" name="rateCount" min="0" style="width:100%; padding: 6px;"></label>
                        </td>
                        <td style="padding-left: 10px; padding-top: 12px; vertical-align: top;">
                            <label>Publication Date:<br>
                            <input type="date" id="editPublicationDate" name="publicationDate" style="width:100%; padding: 6px;"></label>
                        </td>
                    </tr>
                </table>
                <div style="text-align:right;">
                    <button type="button" onclick="SaveFolderDialog()">Save</button>
                    <button type="button" onclick="CloseFolderDialog()" style="background-color:gray">Cancel</button>
                </div>
                <div id="editFolderMsg"></div>
            </form>
        </div>
    </dialog>

</body>
</html>