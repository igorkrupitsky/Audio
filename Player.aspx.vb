Imports System.IO
Imports System.Web.Script.Serialization
Imports System.Linq
Imports System.Data.Odbc
Imports System.Configuration

Public Class Player
    Inherits System.Web.UI.Page
    
    Private Sub Page_Load(ByVal sender As System.Object, ByVal e As System.EventArgs) Handles MyBase.Load
        Dim mode As String = Request("mode")
        If mode = "json" Then
            Dim folderPath As String = Request.Form("folderPath")
            Dim result As Object = New With {
                .CurrentPath = folderPath,
                .CurrentFolder = "",
                .Title = "",
                .TitleUrl = "",
                .TitleRating = "",
                .MyRating = "",
                .RateCount = "",
                .Author = "",
                .Category = "",
                .PubYear = "",
                .PubDate = "",
                .Subfolders = New System.Collections.Generic.List(Of Object),
                .Mp3Files = New System.Collections.Generic.List(Of String)
            }

            Try
                Dim baseFolder As String = Server.MapPath(".")

                If Directory.Exists(folderPath) = False Then
                    folderPath = baseFolder
                    result.CurrentPath = folderPath
                Else
                    If folderPath.Length > baseFolder.Length Then
                        result.CurrentFolder = Replace(folderPath.Substring(baseFolder.Length + 1), "\", "/")
                    End If
                End If

                If Directory.Exists(folderPath) Then

                    For Each sSubFolder As String In Directory.GetDirectories(folderPath)
                        If Directory.GetDirectories(sSubFolder).Length > 0 OrElse Directory.GetFiles(sSubFolder, "*.mp3").Length > 0 Then
                            'Subfolder has to have mp3 files or other subfolders
                            Dim oFolder As Object = New With {
                                .Folder = sSubFolder,
                                .Title = "",
                                .TitleUrl = "",
                                .TitleRating = "",
                                .MyRating = "",
                                .RateCount = "",
                                .Author = "",
                                .Category = "",
                                .PubYear = "",
                                .PubDate = ""
                            }
                            SetFolderInfo(sSubFolder, oFolder)
                            result.Subfolders.Add(oFolder)
                        End If
                    Next

                    'result.Subfolders = Directory.GetDirectories(folderPath).ToList()

                    If result.Subfolders.Count = 0 Then
                        Dim filesMp3 As String() = Directory.GetFiles(folderPath, "*.mp3")
                        Dim filesPdf As String() = Directory.GetFiles(folderPath, "*.pdf")
                        Dim filesTxt As String() = Directory.GetFiles(folderPath, "*.txt")
                        Dim files As String() = filesMp3.Concat(filesPdf.Concat(filesTxt)).ToArray()

                        Dim webPaths As New System.Collections.Generic.List(Of String)

                        For Each f As String In files
                            Dim sFileName As String = IO.Path.GetFileName(f).ToLower()
                            If sFileName <> "index.txt" And sFileName <> "rating.txt" Then
                                Dim rel As String = f.Substring(folderPath.Length)
                                rel = rel.TrimStart(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
                                rel = rel.Replace(Path.DirectorySeparatorChar, "/"c)
                                rel = rel.Replace(Path.AltDirectorySeparatorChar, "/"c)
                                webPaths.Add(rel)
                            End If
                        Next

                        result.Mp3Files = webPaths
                        SetFolderInfo(folderPath, result)
                    End If
                End If

                Response.ContentType = "application/json"
                Dim js As New JavaScriptSerializer()
                Response.Write(js.Serialize(result))
            Catch ex As Exception
                Response.StatusCode = 500
                Response.ContentType = "application/json"
                Response.Write("{""error"":""Server error: " & ex.Message.Replace("""", "'") & """}")
            End Try

            Response.End()

        ElseIf mode = "basepath" Then
            Response.ContentType = "application/json"
            Response.Write("{""basePath"":""" & Server.MapPath(".").Replace("\", "\\") & """,""OS"":""Windows""}")
            Response.End()
            
        ElseIf mode = "updateFolder" Then
            Dim folderPath As String = Request.Form("folderPath")
            Dim title As String = Request.Form("title")
            Dim titleUrl As String = Request.Form("titleUrl")
            Dim myRating As String = Request.Form("myRating")
            Dim rate As String = Request.Form("rate")
            Dim rateCount As String = Request.Form("rateCount")
            Dim author As String = Request.Form("author")
            Dim category As String = Request.Form("category")
            Dim publicationDate As String = Request.Form("publicationDate")
            Dim folderName As String = IO.Path.GetFileName(folderPath)
            Try
                Using conn As New OdbcConnection(GetConnectionString())
                    conn.Open()
                    ' Check if record exists
                    Dim checkCmd As New OdbcCommand("SELECT COUNT(*) FROM Folder WHERE FolderName = ?", conn)
                    checkCmd.Parameters.AddWithValue("@FolderName", folderName)
                    Dim count As Integer = CInt(checkCmd.ExecuteScalar())
                    Dim myRateValue As Object = If(IsNumeric(myRating) AndAlso myRating <> "", CDbl(myRating), DBNull.Value)
                    Dim rateValue As Object = If(IsNumeric(rate) AndAlso rate <> "", CDbl(rate), DBNull.Value)
                    Dim rateCountValue As Object = If(IsNumeric(rateCount) AndAlso rateCount <> "", CInt(rateCount), DBNull.Value)
                    Dim pubDateValue As Object = If(publicationDate <> "", publicationDate, DBNull.Value)
                    If count > 0 Then
                        Dim updateCmd As New OdbcCommand("UPDATE Folder SET FolderPath=?, BookName=?, Url=?, MyRate=?, Rate=?, RateCount=?, Author=?, Category=?, PublicationDate=? WHERE FolderName=?", conn)
                        updateCmd.Parameters.AddWithValue("@FolderPath", folderPath)
                        updateCmd.Parameters.AddWithValue("@BookName", title)
                        updateCmd.Parameters.AddWithValue("@Url", titleUrl)
                        updateCmd.Parameters.AddWithValue("@MyRate", myRateValue)
                        updateCmd.Parameters.AddWithValue("@Rate", rateValue)
                        updateCmd.Parameters.AddWithValue("@RateCount", rateCountValue)
                        updateCmd.Parameters.AddWithValue("@Author", author)
                        updateCmd.Parameters.AddWithValue("@Category", category)
                        updateCmd.Parameters.AddWithValue("@PublicationDate", pubDateValue)
                        updateCmd.Parameters.AddWithValue("@FolderName", folderName)
                        updateCmd.ExecuteNonQuery()
                    Else
                        Dim insertCmd As New OdbcCommand("INSERT INTO Folder (FolderPath, FolderName, BookName, Url, MyRate, Rate, RateCount, Author, Category, PublicationDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", conn)
                        insertCmd.Parameters.AddWithValue("@FolderPath", folderPath)
                        insertCmd.Parameters.AddWithValue("@FolderName", folderName)
                        insertCmd.Parameters.AddWithValue("@BookName", title)
                        insertCmd.Parameters.AddWithValue("@Url", titleUrl)
                        insertCmd.Parameters.AddWithValue("@MyRate", myRateValue)
                        insertCmd.Parameters.AddWithValue("@Rate", rateValue)
                        insertCmd.Parameters.AddWithValue("@RateCount", rateCountValue)
                        insertCmd.Parameters.AddWithValue("@Author", author)
                        insertCmd.Parameters.AddWithValue("@Category", category)
                        insertCmd.Parameters.AddWithValue("@PublicationDate", pubDateValue)
                        insertCmd.ExecuteNonQuery()
                    End If
                End Using
                Response.ContentType = "application/json"
                Response.Write("{""success"":true}")
            Catch ex As Exception
                Response.StatusCode = 500
                Response.ContentType = "application/json"
                Response.Write("{""success"":false,""error"":""" & ex.Message.Replace("""", "'") & """}")
            End Try
            Response.End()
        End If

    End Sub

    Private Function GetMySQLODBCDriver() As String
        Dim keyPath As String = "SOFTWARE\ODBC\ODBCINST.INI\ODBC Drivers"
        Dim registryKey As Microsoft.Win32.RegistryKey = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(keyPath)
        
        If registryKey IsNot Nothing Then
            For Each driver As String In registryKey.GetValueNames()
                If driver.ToLower().Contains("mysql") AndAlso driver.ToLower().Contains("odbc") Then
                    ' Prioritize Unicode drivers over ANSI
                    If driver.ToLower().Contains("unicode") Then
                        ' Prioritize newer versions first for Unicode
                        If driver.ToLower().Contains("8.") Then
                            Return driver
                        ElseIf driver.ToLower().Contains("5.") Then
                            Return driver
                        ElseIf driver.ToLower().Contains("3.") Then
                            Return driver
                        End If
                    End If
                End If
            Next
            
            ' If no Unicode driver found, look for any MySQL ODBC driver as fallback
            For Each driver As String In registryKey.GetValueNames()
                If driver.ToLower().Contains("mysql") AndAlso driver.ToLower().Contains("odbc") Then
                    If driver.ToLower().Contains("8.") Then
                        Return driver
                    ElseIf driver.ToLower().Contains("5.") Then
                        Return driver
                    ElseIf driver.ToLower().Contains("3.") Then
                        Return driver
                    End If
                End If
            Next
            registryKey.Close()
        End If
        
        Return "{MySQL ODBC 8.0 Unicode Driver}"
    End Function
    
Private Function GetConnectionString() As String
    Dim reader As New System.Configuration.AppSettingsReader()
    Dim s As String = reader.GetValue("AudioDb", GetType(String)).ToString()
    Dim driver As String = GetMySQLODBCDriver()
    Return "Driver={" & driver & "};" & s
End Function

    Sub SetFolderInfo(folderPath As String, ByRef oFolder As Object)
        ' Use database instead of text files
        Dim folderName As String = IO.Path.GetFileName(folderPath)

        Try
            Using conn As New OdbcConnection(GetConnectionString())
                conn.Open()
                Dim cmd As New OdbcCommand("SELECT BookName, Url, RateCount, Rate, MyRate, Author, Category, YEAR(PublicationDate) AS PubYear, PublicationDate FROM Folder WHERE FolderName = ?", conn)
                cmd.Parameters.AddWithValue("@FolderName", folderName)
                Using dr As OdbcDataReader = cmd.ExecuteReader()
                    If dr.Read() Then
                        oFolder.Title = dr("BookName").ToString()
                        oFolder.TitleUrl = dr("Url").ToString()
                        oFolder.MyRating = dr("MyRate").ToString()
                        oFolder.TitleRating = dr("Rate").ToString()
                        oFolder.RateCount = dr("RateCount").ToString()
                        oFolder.Author = dr("Author").ToString()
                        oFolder.Category = dr("Category").ToString()
                        oFolder.PubYear = dr("PubYear").ToString()
                        ' Format PubDate as short date string if not null
                        If Not Convert.IsDBNull(dr("PublicationDate")) AndAlso Not String.IsNullOrEmpty(dr("PublicationDate").ToString()) Then
                            Dim pubDateObj As Object = dr("PublicationDate")
                            Dim pubDateStr As String = ""
                            If TypeOf pubDateObj Is Date Then
                                pubDateStr = CType(pubDateObj, Date).ToString("yyyy-MM-dd")
                            Else
                                ' Try to parse as date
                                Dim dt As DateTime
                                If DateTime.TryParse(pubDateObj.ToString(), dt) Then
                                    pubDateStr = dt.ToString("yyyy-MM-dd")
                                Else
                                    pubDateStr = pubDateObj.ToString()
                                End If
                            End If
                            oFolder.PubDate = pubDateStr
                        Else
                            oFolder.PubDate = ""
                        End If
                    End If
                End Using
            End Using
        Catch ex As Exception
            oFolder.Title = ""
            oFolder.TitleUrl = ""
            oFolder.MyRating = ""
            oFolder.TitleRating = ""
            oFolder.RateCount = ""
            oFolder.Author = ""
            oFolder.Category = ""
            oFolder.PubYear = ""
            oFolder.PubDate = ""
        End Try
    End Sub


End Class
