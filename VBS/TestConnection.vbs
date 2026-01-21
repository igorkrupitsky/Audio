Set cn = CreateObject("ADODB.Connection")
Dim sConnStr: sConnStr = "Driver={" & GetMySQLODBCDriver() & "};Server=162.250.127.234;Database=st62437_Audio;User=st62437_Audio;Password=rpMzkqmJCnwPmKJMQ6Hw;Option=3;"
MsgBox sConnStr
cn.Open sConnStr
cn.Close
MsgBox "Success"

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
