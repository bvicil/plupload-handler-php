<?php
class PluploadFTP extends PluploadHandler
{
    /**
     * Handle FTP Upload
     *
     * @return nothing
     * @param array Configuration Files
     */
    static function handleFTP($conf = array())
    {
        // 5 minutes execution time
        @set_time_limit(5 * 60);

        parent::$_error = null; // start fresh

        $conf = self::$conf = array_merge(array(
            'file_data_name' => 'file',
            'tmp_dir' => ini_get("upload_tmp_dir") . DIRECTORY_SEPARATOR . "plupload",
            'target_dir' => false,
            'rel_dir' => false,
            'cleanup' => true,
            'max_file_age' => 5 * 3600,
            'chunk' => isset($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0,
            'chunks' => isset($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 0,
            'file_name' => isset($_REQUEST['name']) ? $_REQUEST['name'] : false,
            'filename' => isset($_REQUEST['filename']) ? $_REQUEST['filename'] : false,
            'allow_extensions' => false,
            'delay' => 0,
            'cb_sanitize_file_name' => array(__CLASS__, 'sanitize_file_name'),
            'cb_check_file' => false,
            'ftphost' => false,
            'ftpuser' => false,
            'ftppass' => false,
        ), $conf);

        try {
            if (!$conf['file_name']) {
                if (!empty($_FILES)) {
                    $conf['file_name'] = $_FILES[$conf['file_data_name']]['name'];
                } else {
                    throw new Exception('', PLUPLOAD_INPUT_ERR);
                }
            }

            // Cleanup outdated temp files and folders
            if ($conf['cleanup']) {
                self::cleanupFTP();
            }

            // Fake network congestion
            if ($conf['delay']) {
                usleep($conf['delay']);
            }

            if (is_callable($conf['cb_sanitize_file_name'])) {
                $file_name = call_user_func($conf['cb_sanitize_file_name'], $conf['file_name']);
            } else {
                $file_name = $conf['file_name'];
            }

            if($conf['filename']) $file_name = $conf['filename'];

            // Check if file type is allowed
            if ($conf['allow_extensions']) {
                if (is_string($conf['allow_extensions'])) {
                    $conf['allow_extensions'] = preg_split('{\s*,\s*}', $conf['allow_extensions']);
                }

                if (!in_array(strtolower(pathinfo($file_name, PATHINFO_EXTENSION)), $conf['allow_extensions'])) {
                    throw new Exception('', PLUPLOAD_TYPE_ERR);
                }
            }

            $file_path = rtrim($conf['target_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file_name;
            $tmp_path = $file_path . ".part";

            // Write file or chunk to appropriate temp location
            if ($conf['chunks']) {
                self::write_file_toFTP("$file_path.dir.part" . DIRECTORY_SEPARATOR . $conf['chunk']);
                // Check if all chunks already uploaded
                if ($conf['chunk'] == $conf['chunks'] - 1) {
                    self::write_chunks_to_fileFTP("$file_path.dir.part", $tmp_path);
                }
            } else {
                self::write_file_toFTP($tmp_path);
            }


            // Upload complete write a temp file to the final destination
            if (!$conf['chunks'] || $conf['chunk'] == $conf['chunks'] - 1) {
                if (is_callable($conf['cb_check_file']) && !call_user_func($conf['cb_check_file'], $tmp_path)) {
                    //@self::rrmdirFTP($tmp_path);
                    throw new Exception('', PLUPLOAD_SECURITY_ERR);
                }

                $conn_id = self::ftpConnect();
                ftp_rename($conn_id, $tmp_path, $file_path);
                ftp_close($conn_id);

                return array(
                    'name' => $file_name,
                    'path' => $file_path,
                    'size' => filesize($file_path)
                );
            }

            // ok so far
            return true;

        } catch (Exception $ex) {
            parent::$_error = $ex->getCode();
            return false;
        }
    }

    /**
     * Writes either a multipart/form-data message or a binary stream
     * to the specified file with FTP.
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * @param string $file_path The path to write the file to
     * @param bool $file_data_name The name of the multipart field
     */
    static function write_file_toFTP($file_path, $file_data_name = false)
    {
        if (!$file_data_name) $file_data_name = self::$conf['file_data_name'];

        if (!empty($_FILES) && isset($_FILES[$file_data_name])) {
            if ($_FILES[$file_data_name]["error"] || !is_uploaded_file($_FILES[$file_data_name]["tmp_name"])) {
                throw new Exception('', PLUPLOAD_MOVE_ERR);
            }

            $file_path = str_replace("\\","/", substr($file_path, 1, strlen($file_path)));

            $destination = "ftp://".self::$conf['ftpuser'].":".self::$conf['ftppass']."@".self::$conf['ftphost']."/".$file_path;
            $ch = curl_init();
            $localfile = $_FILES[$file_data_name]['tmp_name'];
            $fp = fopen($localfile, 'r');
            curl_setopt($ch, CURLOPT_URL, $destination);
            curl_setopt($ch, CURLOPT_UPLOAD, 1);
            curl_setopt($ch, CURLOPT_INFILE, $fp);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localfile));
            curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, 1);
            curl_exec ($ch);
            $error_no = curl_errno($ch);
            curl_close ($ch);
            if ($error_no != 0) throw new Exception('', PLUPLOAD_FTPUP_ERR);

        } else {
            // Handle binary streams
            if (!$in = @fopen("php://input", "rb")) throw new Exception('', PLUPLOAD_INPUT_ERR);

            $myresult = self::ftpupload($in, $file_path);
            if($myresult != 0) throw new Exception('', PLUPLOAD_FTPCH_ERR);

            @fclose($in);
        }
    }


    /**
     * Combine chunks from the specified folder into the single file with FTP.
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * @param string $file_path The file to write the chunks to
     */
    static function write_chunks_to_fileFTP($chunk_dir, $file_path)
    {
        $base_dir = dirname($file_path);
        $newFile = str_replace($base_dir.DIRECTORY_SEPARATOR, "", $chunk_dir);

        $chunk_file = self::$conf['rel_dir'].DIRECTORY_SEPARATOR.$newFile;

        for ($i = 0; $i < self::$conf['chunks']; $i++) {
            $chunk_path = $chunk_file . DIRECTORY_SEPARATOR . $i;
            if (!file_exists($chunk_path)) throw new Exception('', PLUPLOAD_CHDIR_ERR);
            if (!$in = @fopen($chunk_path, "rb")) throw new Exception('', PLUPLOAD_CHFILE_ERR);

            $myresult = self::ftpupload($chunk_path, $file_path);
            if($myresult != 0) throw new Exception('', PLUPLOAD_FTPCH_ERR);

            fclose($in);
        }

        // Cleanup
        self::rrmdirFTP($newFile);
    }


    /**
     * Concise way to recursively remove a directory with FTP
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * @param string $dir Directory to remove
     */
    private static function rrmdirFTP($dir)
    {
        $dir = self::$conf['target_dir'].DIRECTORY_SEPARATOR.$dir;

        $conn_id = self::ftpConnect();
        ftp_chdir($conn_id, $dir);

        $contents = ftp_nlist($conn_id, ".");

        foreach($contents as $file){
            if(is_dir(self::$conf['rel_dir'].DIRECTORY_SEPARATOR.$file))
                self::rrmdirFTP($file);
            else
                @ftp_delete($conn_id, $file);
        }
        ftp_rmdir($conn_id, $dir);
        ftp_close($conn_id);
    }


    /**
     * Cleanup outdated temp files and folders
     *
     */
    private static function cleanupFTP()
    {
        /*
        $uploadDir = self::$conf['target_dir'];

        $conn_id = self::ftpConnect();
        ftp_chdir($conn_id, $uploadDir);

        $contents = ftp_nlist($conn_id, ".");
        foreach($contents as $file){
            if(is_dir(self::$conf['rel_dir'].DIRECTORY_SEPARATOR.$file)){
                $is_part = substr($file, strlen($file) -5);
                if($is_part == ".part"){
                    echo time() .":". filemtime($file).":". self::$conf['max_file_age']."\r\n";
                    if(time() - ftp_mdtm($conn_id, $file) < self::$conf['max_file_age']){
                        continue;
                    }
                    self::rrmdirFTP($file);
                }
            }
        }
        ftp_close($conn_id);
        */
    }

    /**
     * Connect to FTP server
     *
     * @return resource ftp resource
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * */
    private static function ftpConnect(){
        $conn_id = ftp_connect(self::$conf['ftphost']);
        if (!$conn_id) throw new Exception('', PLUPLOAD_FTP_ERR);

        ftp_login($conn_id, self::$conf['ftpuser'], self::$conf['ftppass']);
        ftp_pasv($conn_id, true);

        return $conn_id;
    }

    /**
     * FTP upload with append mode
     *
     * @return bool true/error code
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * @param string $chunk_file chunk file
     * @param string $file_path last file
     *
     */
    private static function ftpupload( $chunk_file , $file_path )
    {
        $file_path = str_replace(DIRECTORY_SEPARATOR,"/", substr($file_path, 1, strlen($file_path)));
        $destination = "ftp://".self::$conf['ftpuser'].":".self::$conf['ftppass']."@".self::$conf['ftphost']."/".$file_path;

        $ch = curl_init();

        if (!$fp = @fopen($chunk_file, "r")) throw new Exception('', PLUPLOAD_INPUT_ERR);

        curl_setopt($ch, CURLOPT_UPLOAD, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLE_OPERATION_TIMEOUTED, 300);
        curl_setopt($ch, CURLOPT_URL, $destination);
        curl_setopt($ch, CURLOPT_FTPAPPEND, TRUE ); // APPEND FLAG
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($chunk_file));
        curl_exec($ch);
        fclose ($fp);
        $errorMsg = '';
        $errorMsg = curl_error($ch);
        $errorNumber = curl_errno($ch);
        curl_close($ch);
        return $errorNumber;
    }
}
