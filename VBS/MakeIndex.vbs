Dim fso: Set fso = CreateObject("Scripting.FileSystemObject")
Set cn = CreateObject("ADODB.Connection")

Dim sConnStrFile: sConnStrFile = GetBaseFolder() & "\db_connection.txt"
Dim sConnStr
If fso.FileExists(sConnStrFile) Then
    Dim fConn: Set fConn = fso.OpenTextFile(sConnStrFile, 1)
    sConnStr = fConn.ReadAll
    fConn.Close
Else
    MsgBox "Missing db_connection.txt. Please create this file with your connection string."
    WScript.Quit
End If

If InStr(sConnStr, "Driver={") = 0 Then
    sConnStr = "Driver={" & GetMySQLODBCDriver() & "};" & sConnStr
End If

cn.Open sConnStr

cn.Execute "update Folder set FolderExists = null"

ReadFolder "D:\Audio"

'Copy data from old folders
sSql = "UPDATE Folder a" & _
    "  JOIN Folder b ON b.FolderName = a.FolderName " & _
    " SET a.BookName = b.BookName, " & _
    " a.Url = b.Url, " & _
    " a.PublicationDateText = b.PublicationDateText," & _
    " a.PublicationDate = b.PublicationDate," & _
    " a.Author = b.Author," & _
    " a.Category = b.Category," & _
    " a.RateCount = b.RateCount," & _
    " a.Rate = b.Rate," & _
    " a.MyRate = b.MyRate" & _
    " WHERE a.FolderExists = 1" & _
    "  AND a.Url IS null" & _
    "  AND b.FolderExists IS null" 
cn.Execute sSql

'Update UserRating.FolderId to new existing folders
sSql = "UPDATE UserRating r" & _
    "  JOIN Folder old ON old.FolderId = r.FolderId " & _
    "  JOIN Folder new ON new.FolderName = old.FolderName " & _
    " SET r.FolderId = new.FolderId, " & _
    " WHERE new.FolderExists = 1" & _
    "  AND old.FolderExists IS null" 
cn.Execute sSql

sOldFolderCount = GetSqlVal("select count(*) cnt from Folder where FolderExists is null")
If sOldFolderCount <> "" Then
    If MsgBox("Delete " & sOldFolderCount & " old folders from the DB?", vbYesNo + vbQuestion) = vbYes Then
        cn.Execute "delete from Folder where FolderExists is null"
    End If
End If

cn.Close

MsgBox "Done"

'--------------------------------------------------------------
Function GetBaseFolder()
  Set oFile = fso.GetFile(WScript.ScriptFullName)
  GetBaseFolder = oFile.ParentFolder
End Function

Function GetSqlVal(sSql)
    Dim rs: Set rs = cn.Execute(sSql)     
    If Not rs.EOF Then
        GetSqlVal = rs(0) & ""
    End If
    rs.Close
Set rs = Nothing
End Function

Sub ReadFolder(sFolderPath)
    Dim sLink 'As String
    Dim oSubFolder 'As Scripting.Folder
    Dim oFolder: Set oFolder = fso.GetFolder(sFolderPath)
    Dim bHasMp3Files: bHasMp3Files = false
    Dim oFile

    For Each oFile In oFolder.Files
        If LCase(Right(oFile.Name,4)) = ".mp3" Then
            bHasMp3Files = True
            Exit For
        End If
    Next

    If oFolder.SubFolders.Count = 0 Then
        If bHasMp3Files Then

            If GetSqlVal("select count(*) cnt from Folder where FolderPath = '" & Replace(sFolderPath, "'", "''") & "'") = 0 Then

                sSql = "UPDATE Folder SET FolderExists = 1, FolderUpdated = CURDATE()" & _
                        " WHERE FolderPath = '" & Replace(Replace(sFolderPath, "'", "''"), "\", "\\") & "'"
                cn.Execute sSql           

            Else
                sSql = "INSERT INTO Folder (FolderPath, FolderName, FolderExists, FolderUpdated) " & _
                        " VALUES ('" & Replace(Replace(sFolderPath, "'", "''"), "\", "\\") & "'," & _
                                 "'" & Replace(fso.GetFileName(sFolderPath), "'", "''") & "', 1, CURDATE())"            
                cn.Execute sSql
            End If          

        End If 'bHasMp3Files

    Else 'oFolder.SubFolders.Count > 0
        For Each oSubFolder In oFolder.SubFolders
            If oSubFolder.Name <> "System Volume Information" And _
                oSubFolder.Name <> "RECYCLER" And _
                oSubFolder.Name <> "DELETE" And _
                oSubFolder.Name <> "Download" And _
                oSubFolder.Name <> "Videos" And _
                oSubFolder.Name <> "$RECYCLE.BIN" And _
                oSubFolder.Name <> "images" And _
                oSubFolder.Name <> "Backup" And _
                oSubFolder.Name <> "msdownld.tmp" Then
                ReadFolder oSubFolder.Path
            End If
        Next
    End If

End Sub

Function GetMySQLODBCDriver()
    Dim shell, regPath, drivers, driver
    Dim preferredDriver

    regPath = "HKEY_LOCAL_MACHINE\SOFTWARE\ODBC\ODBCINST.INI\ODBC Drivers\"
    Set shell = CreateObject("WScript.Shell")
    Set drivers = CreateObject("Scripting.Dictionary")

    On Error Resume Next
    Dim oReg : Set oReg = GetObject("winmgmts:\\.\root\default:StdRegProv")
    Dim keyPath : keyPath = "SOFTWARE\ODBC\ODBCINST.INI\ODBC Drivers"
    Dim arrNames, arrTypes

    oReg.EnumValues &H80000002, keyPath, arrNames, arrTypes

    If IsArray(arrNames) Then
        ' First pass: prioritize Unicode
        For Each driver In arrNames
            ldriver = LCase(driver)
            If InStr(ldriver, "mysql") > 0 And InStr(ldriver, "odbc") > 0 Then
                If InStr(ldriver, "unicode") > 0 Then
                    If InStr(ldriver, "8.") > 0 Then
                        GetMySQLODBCDriver = driver
                        Exit Function
                    ElseIf InStr(ldriver, "5.") > 0 Then
                        GetMySQLODBCDriver = driver
                        Exit Function
                    ElseIf InStr(ldriver, "3.") > 0 Then
                        GetMySQLODBCDriver = driver
                        Exit Function
                    End If
                End If
            End If
        Next

        ' Second pass: any MySQL ODBC driver
        For Each driver In arrNames
            ldriver = LCase(driver)
            If InStr(ldriver, "mysql") > 0 And InStr(ldriver, "odbc") > 0 Then
                If InStr(ldriver, "8.") > 0 Then
                    GetMySQLODBCDriver = driver
                    Exit Function
                ElseIf InStr(ldriver, "5.") > 0 Then
                    GetMySQLODBCDriver = driver
                    Exit Function
                ElseIf InStr(ldriver, "3.") > 0 Then
                    GetMySQLODBCDriver = driver
                    Exit Function
                End If
            End If
        Next
    End If

    ' Default fallback
    GetMySQLODBCDriver = "MySQL ODBC 8.0 Unicode Driver"
End Function
