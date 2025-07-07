<!DOCTYPE html>
<html>
<head>
    <title>SQL Runner</title>
    <!-- Bootstrap and DataTables CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    
    <style>
        .spinner-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left: 4px solid #1877f2;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0%   {transform: rotate(0deg);}
            100% {transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <h2>SQL Query Runner</h2>
    <form method="post" onsubmit="encodeSQL();">
        <div class="mb-3">
            <label for="savedQueries" class="form-label">Load Saved Query:</label>
            <div class="d-flex gap-2">
                <select id="savedQueries" class="form-select" onchange="loadSavedQuery()">
                    <option value="">-- Select a saved query --</option>
                </select>
                <button type="button" onclick="clearSelectedQuery()" class="btn btn-outline-danger" id="clearSelectedBtn" disabled>Clear</button>
            </div>
        </div>
        <div class="mb-3">
            <textarea id="sqlInput" class="form-control" rows="8" placeholder="Write your SQL query here..." onkeydown="handleTab(event)"><?php echo isset($_POST['encoded_sql']) ? htmlspecialchars(base64_decode($_POST['encoded_sql'])) : ''; ?></textarea>
            <input type="hidden" name="encoded_sql" id="encoded_sql">
        </div>
        <button type="submit" name="run" value="Run" class="btn btn-primary" onclick="ShowSpinner()">Run</button>
    </form>
    
    <script>
    // Load saved queries on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadSavedQueriesList();
        HideSpinner();
    });

    function encodeSQL() {
        const rawSQL = document.getElementById('sqlInput').value;
        document.getElementById('encoded_sql').value = btoa(unescape(encodeURIComponent(rawSQL)));
    }

    function handleTab(event) {
        if (event.key === 'Tab') {
            event.preventDefault();
            const textarea = event.target;
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const value = textarea.value;
            
            if (event.shiftKey) {
                // Shift+Tab: De-indent
                const beforeCursor = value.substring(0, start);
                const afterCursor = value.substring(end);
                const lines = value.substring(start, end).split('\n');
                
                const processedLines = lines.map(line => {
                    if (line.startsWith('    ')) {
                        return line.substring(4);
                    } else if (line.startsWith('\t')) {
                        return line.substring(1);
                    }
                    return line;
                });
                
                const newValue = beforeCursor + processedLines.join('\n') + afterCursor;
                textarea.value = newValue;
                textarea.selectionStart = start;
                textarea.selectionEnd = start + processedLines.join('\n').length;
            } else {
                // Tab: Indent
                const beforeCursor = value.substring(0, start);
                const afterCursor = value.substring(end);
                const selectedText = value.substring(start, end);
                
                if (selectedText.includes('\n')) {
                    // Multiple lines selected
                    const lines = selectedText.split('\n');
                    const indentedLines = lines.map(line => '    ' + line);
                    const newValue = beforeCursor + indentedLines.join('\n') + afterCursor;
                    textarea.value = newValue;
                    textarea.selectionStart = start;
                    textarea.selectionEnd = start + indentedLines.join('\n').length;
                } else {
                    // Single line or no selection
                    const newValue = beforeCursor + '    ' + afterCursor;
                    textarea.value = newValue;
                    textarea.selectionStart = start + 4;
                    textarea.selectionEnd = start + 4;
                }
            }
        }
    }

    function loadSavedQueriesList() {
        const savedQueries = JSON.parse(localStorage.getItem('savedQueries') || '{}');
        const select = document.getElementById('savedQueries');
        
        // Clear existing options except the first one
        select.innerHTML = '<option value="">-- Select a saved query --</option>';
        
        Object.keys(savedQueries).forEach(queryKey => {
            const option = document.createElement('option');
            option.value = queryKey;
            option.textContent = queryKey;
            select.appendChild(option);
        });
    }

    function loadSavedQuery() {
        const select = document.getElementById('savedQueries');
        const selectedQuery = select.value;
        const clearBtn = document.getElementById('clearSelectedBtn');
        
        if (selectedQuery) {
            const savedQueries = JSON.parse(localStorage.getItem('savedQueries') || '{}');
            document.getElementById('sqlInput').value = savedQueries[selectedQuery];
            clearBtn.disabled = false;
        } else {
            clearBtn.disabled = true;
        }
    }

    function clearSelectedQuery() {
        const select = document.getElementById('savedQueries');
        const selectedQuery = select.value;
        
        if (selectedQuery) {
            const savedQueries = JSON.parse(localStorage.getItem('savedQueries') || '{}');
            delete savedQueries[selectedQuery];
            localStorage.setItem('savedQueries', JSON.stringify(savedQueries));
            
            loadSavedQueriesList();
            document.getElementById('clearSelectedBtn').disabled = true;
        }
    }

    // Function to save successful query automatically
    function saveSuccessfulQuery(sql) {
        // Get the first 50 characters of the SQL as the key
        const queryKey = sql.substring(0, 50).trim();
        
        const savedQueries = JSON.parse(localStorage.getItem('savedQueries') || '{}');
        savedQueries[queryKey] = sql;
        localStorage.setItem('savedQueries', JSON.stringify(savedQueries));
        
        loadSavedQueriesList();
    }

    function ShowSpinner() {
        document.getElementById('spinnerContainer').style.display = "";
    }

    function HideSpinner() {
        document.getElementById('spinnerContainer').style.display = "none";
    }
    </script>

    <?php
    // Increase execution time and memory limits for long-running queries
    set_time_limit(300); // 5 minutes
    ini_set('max_execution_time', 300);
    ini_set('memory_limit', '512M');
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);

    if (isset($_POST['run']) && !empty($_POST['encoded_sql'])) {
        $config = require __DIR__ . '/config.php';
        $host = $config['DB_HOST'];
        $db = $config['DB_NAME'];
        $user = $config['DB_USER'];
        $pass = $config['DB_PASS'];

        $encodedSQL = $_POST['encoded_sql'];
        $sql = base64_decode($encodedSQL);

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = null;

        try {
            $mysqli = mysqli_connect($host, $user, $pass, $db);

            if ($result = mysqli_query($mysqli, $sql)) {
                if ($result instanceof mysqli_result) {
                    echo "<hr><table id='resultsTable' class='table table-striped table-bordered'><thead><tr>";
                    while ($fieldinfo = mysqli_fetch_field($result)) {
                        echo "<th>{$fieldinfo->name}</th>";
                    }
                    echo "</tr></thead><tbody>";
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>";
                        foreach ($row as $cell) {
                            echo "<td>" . htmlspecialchars($cell . "") . "</td>";
                        }
                        echo "</tr>";
                    }
                    echo "</tbody></table>";
                    echo "<script>$(document).ready(function() { $('#resultsTable').DataTable( { dom: 'lBfrtip', buttons: [ 'copy', 'csv', 'excel' ], lengthMenu: [[10, 25, 50, 100, -1], ['10 rows', '25 rows', '50 rows', '100 rows', 'Show all']] } ); });</script>";
                    echo "<script>saveSuccessfulQuery(" . json_encode($sql) . ");</script>";
                    mysqli_free_result($result);
                } else {
                    echo "<div class='alert alert-success mt-3'>Query executed successfully.</div>";
                    echo "<script>saveSuccessfulQuery(" . json_encode($sql) . ");</script>";
                }
            }
        } catch (mysqli_sql_exception $e) {
            echo "<div class='alert alert-danger mt-3'><strong>SQL Error:</strong><br>" . $e->getMessage() . "</div>";
        } finally {
            if ($mysqli) {
                mysqli_close($mysqli);
            }
        }
    }
    ?>
</div>

<div id="spinnerContainer" class="spinner-container"><div class="spinner"></div></div>
</body>
</html>
