Set fso = CreateObject("Scripting.FileSystemObject")
Dim oShell: Set oShell = CreateObject("WScript.Shell")
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

InstallSeleniumBasic
InstallSeleniumEdgeDriver

Set ie = CreateObject("Selenium.EdgeDriver")

'Updated over a 1 month ago
sFolderSql = "select FolderName, FolderPath, Url " & _
            " FROM Folder " & _
            " WHERE (UrlUpdated < DATE_SUB(CURDATE(), INTERVAL 1 MONTH) or UrlUpdated IS NULL) " 

Set rs = cn.Execute(sFolderSql)     
Do While Not rs.EOF
    UpdateFolder GetParentFolderName(rs("FolderPath") & ""), rs("FolderName") & "", rs("Url") & ""
    rs.MoveNext
Loop

MsgBox "Done"

'========================================================
Function GetParentFolderName(path)

    path = Replace(path,"/","\")

    If Right(path, 1) = "\" Then
        path = Left(path, Len(path) - 1)
    End If

    Dim pos: pos = InStrRev(path, "\")
    If pos = 0 Then
        GetParentFolderName = path
        Exit Function
    End If

    Dim parentPath: parentPath = Left(path, pos - 1)
    GetParentFolderName = Mid(parentPath, InStrRev(parentPath, "\") + 1)
End Function

Sub UpdateFolder(sParentFolder, sFolderName, sUrl)
    
    oSiteList = Array("amazon","audible","thegreatcoursesplus.com","ozon.ru")

    If sUrl = "" Then
        bUrlUpdated = True
        sUrl = GetUrl(sFolderName, oSiteList)

        If sUrl = "" Then
            sUrl = GetUrl(sParentFolder & " - " & sFolderName, oSiteList)
        End If
    End If

    If sUrl = "" Then
        SetUrlUpdated sFolderName
        Exit Sub
    End If

    bGoodSite = False
    For Each sSite in oSiteList
        If InStr(lcase(sUrl),sSite) <> 0 Then
            bGoodSite = True
        End If
    Next
    If bGoodSite = False Then
        SetUrlUpdated sFolderName
        Exit Sub
    End If

    ie.Get sUrl
    WaitForIE

    Dim sTitle, sStars, sRateCount, sCategory, sAuthor, sPublicationDate

    on error resume next

    If InStr(lcase(sUrl),"amazon.com") <> 0 Then

        If InStr(ie.ExecuteScript("return document.documentElement.innerHTML"), "Sorry! Something went wrong on our end.") <> 0 Then
            Exit Sub
        End If

        sTitle = ie.ExecuteScript("return title.innerText") 
        sStars = ie.ExecuteScript("return document.querySelector('i.a-icon-star').innerText")
        sRateCount = ie.ExecuteScript("return document.querySelector('#acrCustomerReviewLink').innerText")        
        sCategory = ie.ExecuteScript("return document.querySelector('.a-unordered-list').innerText.replaceAll('\n','')")  'Books›Computers & Technology›Programming›Algorithms

        sAuthor = ie.ExecuteScript("return document.querySelector('.author').innerText")
        If sAuthor = "" Then
            sAuthor = ie.ExecuteScript("return document.querySelector('tr.po-contributor td:nth-child(2) span').textContent.trim()")
        End If

    ElseIf InStr(lcase(sUrl),"audible.com") <> 0 Then
        sTitle = ie.ExecuteScript("return document.querySelector('adbl-title-lockup').innerText")
        sStars = ie.ExecuteScript("return document.querySelector('adbl-star-rating').shadowRoot.querySelector('.value-label').innerText")
        sRateCount = ie.ExecuteScript("return document.querySelector('adbl-star-rating').shadowRoot.querySelector('.count-label').innerText")
        sCategory = ie.ExecuteScript("return [...document.querySelectorAll('adbl-product-metadata')[1].shadowRoot.querySelectorAll('.line')].find(line => line.querySelector('.label')?.textContent.trim() === 'Categories') ?.querySelector('.text, .value')?.textContent.trim()")
        sAuthor = ie.ExecuteScript("return document.querySelector('adbl-product-metadata').shadowRoot.querySelector('.authors-narrators-wrapper div').innerText")
        If sAuthor = "" Then
            sAuthor = ie.ExecuteScript("return document.querySelector('adbl-product-metadata').shadowRoot.querySelector('div.line').innerText")
        End If

        ie.ExecuteScript "window.items = document.querySelectorAll('#detailBullets_feature_div li')"
        sPublicationDate = ie.ExecuteScript("return Array.from(items).find(item => item.textContent.includes('Publication date'))?.querySelector('span:nth-child(2)')?.textContent.trim()")
        If sPublicationDate = "" Then
            sPublicationDate = ie.ExecuteScript("return [...document.querySelectorAll('adbl-product-metadata')[1].shadowRoot.querySelectorAll('.line')].find(line => line.querySelector('.label')?.textContent.trim() === 'Release date')?.querySelector('.text')?.textContent.trim()")
        End If

    ElseIf InStr(lcase(sUrl),"thegreatcoursesplus.com") <> 0 Then
        sTitle = ie.ExecuteScript("return document.querySelector('h1').innerText")
        sStars = ie.ExecuteScript("return document.querySelector('.bv_avgRating_component_container').innerText")
        sRateCount = ie.ExecuteScript("return document.querySelector('.bv_numReviews_text').innerText")
    
    ElseIf InStr(lcase(sUrl),"ozon.ru") <> 0 Then
        sScore = ie.ExecuteScript("return document.querySelector('div[data-widget='webSingleProductScore']').innerText")
        If sScore <> "" Then
            '4.9 • 308 отзывов'
            oScore = Split(sScore,"•")
            If Ubound(oScore) = 1 Then
                sStars     = oScore(0)
                sRateCount = oScore(1)
            End If
        End If
    Else
        Exit Sub
    End If 

    Dim sRate: sRate = ""
    If sStars <> "" Then
        sRate = Split(sStars, " ")(0)  
        if IsNumeric(sRate) Then
            sRate = CStr(Cdbl(sRate)) 
        End If
    End If    

    If sRateCount <> "" Then'155 ratings
        sRateCount = Split(sRateCount, " ")(0)  
        sRateCount = Replace(sRateCount,",","")
    End If  

    iPos = InStr(sUrl, "#")
    If iPos > 5 Then
        sUrl = Left(sUrl, iPos - 1)
    End If

    If sAuthor <> "" Then
        iPos = InStr(sAuthor,"(")
        If iPos > 2 Then
            sAuthor = Left(sAuthor, iPos-1)
        End If
    End If

    Dim sUpdateSql: sUpdateSql = "UPDATE Folder SET UrlUpdated = CURDATE(), " & _
        "Url                 = COALESCE(" & PadQuotes(sUrl) & ", Url), " & _
        "BookName            = COALESCE(" & PadQuotes(sTitle) & ", BookName), " & _
        "Rate                = COALESCE(" & PadNumber(sRate & "") & ", Rate), " & _
        "RateCount           = COALESCE(" & PadNumber(sRateCount & "") & ", RateCount), " & _
        "Category            = COALESCE(" & PadQuotes(sCategory) & ", Category), " & _
        "Author              = COALESCE(" & PadQuotes(sAuthor) & ", Author), " & _
        "PublicationDate     = COALESCE(" & PadDate(sPublicationDate) & ", PublicationDate), " & _
        "PublicationDateText = COALESCE(" & PadQuotes(sPublicationDate) & ", PublicationDateText) " & _
        "WHERE FolderName = " & PadQuotes(sFolderName) 
    cn.Execute sUpdateSql
End Sub

Sub SetUrlUpdated(sFolderName)
    Dim sSql: sSql = "UPDATE Folder SET UrlUpdated = CURDATE() WHERE FolderName = " & PadQuotes(sFolderName) 
    cn.Execute sSql
End Sub

Function PadQuotes(s) 
    If s <> "" Then
        PadQuotes = "'" & Replace(s, "'", "''") & "'"
    Else
        PadQuotes = "NULL"
    End If
End Function

Function PadDate(s) 
    Dim dt
    If IsDate(s) Then
        dt = CDate(s)
        PadDate = "'" & Year(dt) & "-" & Right("0" & Month(dt), 2) & "-" & Right("0" & Day(dt), 2) & "'"
    Else
        PadDate = "NULL" 
    End If
End Function

Function PadNumber(s) 
    If IsNumeric(s) Then
        PadNumber = s
    Else
        PadNumber = "NULL" 
    End If
End Function

Sub WaitForIE
    Do While ie.ExecuteScript("return document.readyState") <> "complete"
        WScript.Sleep 100
    Loop
End Sub

Function GetUrl(sBookName, oSiteList)
        ie.Get "https://www.google.com/search?q=" & Replace(sBookName," ","+")
        WaitForIE

        If InStr(ie.ExecuteScript("return document.documentElement.innerHTML"), "This page checks to see if it's really you sending the requests, and not a robot.") <> 0 Then
            MsgBox "Tell Google you are not a robot"
        End If

        on error resume next
    
        Dim sUrl

        For Each sSite in oSiteList
            If InStr(lcase(sUrl),sSite) <> 0 Then
                sUrl = ie.ExecuteScript("return document.querySelector('a[href^=""https://www." & sSite & """]').href")
            End If
        Next

        If sUrl <> "" Then
            iPos = InStr(sUrl, "?")
            If iPos <> 0 Then
                sUrl = Left(sUrl, iPos - 1)
            End If
        End If

        GetUrl = sUrl
End Function

'=======================================
Sub InstallSeleniumBasic()
    sEdgeDriverPath = GetEdgeDriverPath()
    If fso.FileExists(sEdgeDriverPath) Then 
        'Already installed
        Exit Sub
    End If

    sSetupPath = GetBaseFolder() & "\SeleniumBasic-2.0.9.0.exe"

    If fso.FileExists(sSetupPath) = False Then 
        MsgBox "Could not find setup file (SeleniumBasic-2.0.9.0.exe). Please download it and install it."
        oShell.Run "https://github.com/florentbr/SeleniumBasic/releases/"
        WScript.Quit
    End If

    If fso.FileExists(sSetupPath) Then 
        oShell.Run sSetupPath, 1, True 'Wait till finishd
    End If
End Sub

Sub InstallSeleniumEdgeDriver()
    'Check Selenium Edge Driver Version
    sEdgeDriverPath = GetEdgeDriverPath()
    sSeleniumVersion = GetMajorVersion(fso.GetFileVersion(sEdgeDriverPath))
    sEdgeVersion = GetMajorVersion(oShell.RegRead("HKEY_CURRENT_USER\Software\Microsoft\Edge\BLBeacon\version") & "")
    If sEdgeVersion = sSeleniumVersion Then
        'We are up to date
        Exit Sub
    End If

    sLatestSeleniumPath = DownloadLatestSelenium()
    If sLatestSeleniumPath = "" Then
        Exit Sub
    End If

    sLatestSeleniumVersion = GetMajorVersion(fso.GetFileVersion(sLatestSeleniumPath))

    'Backup downloaded file - we might use it in the future if Edge will get out date
    sBackupFilePath = GetBackupFilePath(sLatestSeleniumPath, sLatestSeleniumVersion)
    If fso.FileExists(sBackupFilePath) = False Then    
        fso.CopyFile sLatestSeleniumPath, sBackupFilePath
    End If

    If sLatestSeleniumVersion = sEdgeVersion Then
        sSrcSeleniumPath = sLatestSeleniumPath
    Else
        'Microsoft Edge does not have the latest version - try to use version from backup
        sSrcSeleniumPath = GetBackupFilePath(sLatestSeleniumPath, sEdgeVersion)
        If fso.FileExists(sSrcSeleniumPath) = False Then  
            sSrcSeleniumPath = ""
        End If
    End If

    If sSrcSeleniumPath = "" Then
        MsgBox "Microsoft Edge does not have the latest version " & sLatestSeleniumVersion & ". It has " & sEdgeVersion & _
            ".  Consider upgrading it (Settings > About Microsoft Edge) "
    Else
        'Delete dest file
        If fso.FileExists(sEdgeDriverPath) Then    
            fso.DeleteFile sEdgeDriverPath, True
        End If

        If fso.FileExists(sEdgeDriverPath) Then 
            MsgBox "Could not delete the current Selenium version.  It might be in use. " & sEdgeDriverPath
        Else
            fso.CopyFile sSrcSeleniumPath, sEdgeDriverPath
        End If
    End If
End Sub

Function GetBackupFilePath(sPath, iVersion)
    GetBackupFilePath = fso.GetParentFolderName(sPath) & "\" & fso.GetBaseName(sPath) & "_" & iVersion & "." & fso.GetExtensionName(sPath)
End Function

Function DownloadLatestSelenium()
    Dim oShell: Set oShell = WScript.CreateObject("WSCript.shell")
    Dim oHttp: Set oHttp = CreateObject("MSXML2.ServerXMLHTTP.6.0") 
    oHttp.Open "GET", "https://developer.microsoft.com/en-us/microsoft-edge/tools/webdriver", False
    oHttp.Send
    Dim s: s = oHttp.responseText
    Dim iPos: iPos = InStr(1, s,"Stable Channel")
    iPos = InStr(iPos, s,"/edgedriver_win64.zip""")
    s = Mid(s, iPos - 50, 100)
    iPos = InStr(1, lcase(s),"https://")
    s = Mid(s, iPos)
    iPos = InStr(1, s, """")
    Dim sUrl: sUrl = Mid(s, 1, iPos - 1)

    Dim sFolder: sFolder = GetBaseFolder()
    Dim sFilePath: sFilePath = sFolder & "\edgedriver_win64.zip"

    If fso.FileExists(sFilePath) Then
      fso.DeleteFile sFilePath, True
    End If

    DownloadFile sUrl, sFilePath

    Dim sEdgeDriverPath: sEdgeDriverPath = sFolder & "\msedgedriver.exe"
    If fso.FileExists(sEdgeDriverPath) Then
      fso.DeleteFile sEdgeDriverPath, True
    End If

    UnzipFile sFilePath, sFolder

    If fso.FileExists(sEdgeDriverPath) Then
        DownloadLatestSelenium = sEdgeDriverPath
    Else
        DownloadLatestSelenium = ""
    End If
End Function

Sub UnzipFile(zipPath, destFolder)
    Dim winrarPath, shell, cmd, archiveName
    winrarPath = "C:\Program Files\WinRAR\WinRAR.exe" 

    If fso.FileExists(winrarPath) Then
        ' Use WinRAR to extract the zip
        cmd = Chr(34) & winrarPath & Chr(34) & " x -o+ -ibck " & Chr(34) & zipPath & Chr(34) & " " & Chr(34) & destFolder & Chr(34)
        oShell.Run cmd, 0, True
    Else
        ' Use Shell.Application to extract
        Set shell = CreateObject("Shell.Application")
        Dim source, destination, items
        Set source = shell.NameSpace(zipPath)
        Set destination = shell.NameSpace(destFolder)

        If Not source Is Nothing And Not destination Is Nothing Then
            ' 20 = 16 (Yes to All) + 4 (No UI)
            destination.CopyHere source.Items, 20
            Wscript.Sleep 1000
        Else
            WScript.Echo "Error: Invalid zip file or destination."
        End If
    End If

    Set shell = Nothing
End Sub

Sub DownloadFile(sUrl, sFilePath)
  Dim oHTTP: Set oHTTP = CreateObject("Microsoft.XMLHTTP")
  oHTTP.Open "GET", sUrl, False
  oHTTP.Send

  If oHTTP.Status = 200 Then 
    Set oStream = CreateObject("ADODB.Stream") 
    oStream.Open 
    oStream.Type = 1 
    oStream.Write oHTTP.ResponseBody 
    oStream.SaveToFile sFilePath, 2 
    oStream.Close 
  Else
    WScript.Echo "Error Status: " & oHTTP.Status & ", URL:" & sUrl
  End If
End Sub

Function GetBaseFolder()
  Set oFile = fso.GetFile(WScript.ScriptFullName)
  GetBaseFolder = oFile.ParentFolder
End Function

Function GetMajorVersion(s)
  i = InStr(s,".")
  If i <> 0 Then
    GetMajorVersion = Mid(s, 1,i - 1)
  Else
    GetMajorVersion = s
  End If
End Function

Function GetEdgeDriverPath()
    on error resume next
    Dim sSeleniumFilePath: sSeleniumFilePath = oShell.RegRead("HKEY_CLASSES_ROOT\CLSID\{0277FC34-FD1B-4616-BB19-0809389E78C4}\InprocServer32\CodeBase") 
    Dim oFile: Set oFile = fso.GetFile(sSeleniumFilePath)
    GetEdgeDriverPath = oFile.ParentFolder.Path & "\EdgeDriver.exe"
    If Err.number <> 0 Then
        GetEdgeDriverPath = ""
    End If
End Function


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
