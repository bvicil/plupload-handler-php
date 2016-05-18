<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr">
<head>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8"/>

    <title>Plupload - Getting Started with FTP</title>

    <script type="text/javascript" src="js/plupload.full.min.js"></script>

</head>
<body>

<ul id="filelist"></ul>
<br />

<div id="container">
    <a id="browse" href="javascript:;">[Browse...]</a>
    <a id="start-upload" href="javascript:;">[Start Upload]</a>
</div>

<br />
<pre id="console"></pre>

<script type="text/javascript">

    var uploader = new plupload.Uploader({
        browse_button: 'browse', // this can be an id of a DOM element or the DOM element itself
        url: 'upload.php',
        chunk_size : '1mb',
        unique_names : true,
        init:{
            FilesAdded : function(up, files) {
                var html = '';
                plupload.each(files, function(file) {
                    html += '<li id="' + file.id + '">' + file.name + ' (' + plupload.formatSize(file.size) + ') <b></b></li>';
                });
                document.getElementById('filelist').innerHTML += html;
            },

            BeforeUpload: function (up, file) {
                // Called right before the upload for a given file starts, can be used to cancel it if required
                up.settings.multipart_params = {
                    filename: file.name
                };
            },

            UploadProgress : function(up, file) {
                document.getElementById(file.id).getElementsByTagName('b')[0].innerHTML = '<span>' + file.percent + "%</span>";
            },

            Error : function(up, err) {
                document.getElementById('console').innerHTML += "\nError #" + err.code + ": " + err.message;
            },

            ChunkUploaded: function(up, file, info) {
                document.getElementById('console').innerHTML += "\nChunk: " + info.offset + "/" +info.total;
            }
        }
    });

    uploader.init();

    document.getElementById('start-upload').onclick = function() {
        uploader.start();
    };

</script>
</body>
</html>
