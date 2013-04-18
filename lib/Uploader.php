<?php

class Uploader
{
    /**
     * Allowed extensions.
     *
     * @var  Array
     */
    protected $allowedExtensions;

    /**
     * The reserved file names.
     *
     * @var  Array
     */
    protected $reservedFilenames;

    /**
     * The PDO database connection object.
     *
     * @var  \PDO
     */
    protected $conn;

    /**
     * The file object.
     *
     * @var  mixed
     */
    protected $file;

    /**
     * An array containing information about the current user.
     *
     * @var  Array
     */
    protected $user;

    /**
     * The length limit for file names.
     *
     * @var  integer
     */
    protected $fileNameLengthLimit = 64;

    /**
     * The allowed characters in file names (case-insensitive).
     *
     * @var  string
     */
    protected $fileNameAllowedCharacters = '0123456789abcdefghijklmnopqrstuvwxyz-_.';

    public function __construct(\PDO $conn, Array $user, Array $allowedExtensions, Array $reservedFilenames)
    {
        $allowedExtensions = array_map('strtolower', $allowedExtensions);
        
        $this->allowedExtensions = $allowedExtensions;
        $this->reservedFilenames = $reservedFilenames;
        $this->user = $user;
        $this->conn = $conn;

        if (isset($_GET['qqfile']))
        {
            $this->file = new qqUploadedFileXhr();
        }
        elseif (isset($_FILES['qqfile']))
        {
            $this->file = new qqUploadedFileForm();
        }
        else
        {
            $this->file = null;
        }
    }

    public function handleUpload($path, $hidden)
    {
        if ( ! is_writable($path)) throw new \Exception("Can't write to the upload directory.");

        if ( ! $this->file) throw new \Exception("No file to upload (handler).");

        $size = $this->file->getSize();

        if ($size == 0) throw new \Exception("The file is empty.");
        if ($this->user['filesize'] >= 0 && $size > $this->user['filesize']) throw new \Exception("The file is too large (Max. {$this->user['filesize']} bytes).");

        $fileInfo = pathinfo($this->file->getName());
        $fileInfo['extension'] = mb_strtolower($fileInfo['extension']);

        // Make sure the extension's in the allowed extensions
        if ( ! in_array($fileInfo['extension'], $this->allowedExtensions)) throw new \Exception("An invalid file extension.");

        // Make sure the filename isn't too long
        if (mb_strlen($fileInfo['filename']) > $this->fileNameLengthLimit)
            $fileInfo['filename'] = mb_substr($fileInfo['filename'], 0, $this->fileNameLengthLimit);
        
        // Make sure we're only using allowed characters in the filename
        $characters = preg_split('//u', $fileInfo['filename'], -1, PREG_SPLIT_NO_EMPTY);
        $safeFileName = '';
        foreach ($characters as $character)
        {
            if (false === strpos($this->fileNameAllowedCharacters, mb_strtolower($character)))
                $safeFileName .= '_';
            else
                $safeFileName .= $character;
        }

        $fileInfo['filename'] = $safeFileName;

        // If the file already exists, add some numbers to the file's name
        $tempFileName = $fileInfo['filename'];
        $num = 1;

        do {
            $query = $this->conn->prepare("SELECT * FROM leetup_files WHERE filename = ?");
            $query->execute([$tempFileName . '.' . $fileInfo['extension']]);

            if ( ! $query->fetch()) break;

            $tempFileName = $fileInfo['filename'] . '_' . $num;
            $num++;
        } while(true);

        $fileInfo['filename'] = $tempFileName;

        if (in_array($fileInfo['filename'], $this->reservedFilenames)) throw new \Exception("An invalid filename.");

        // Alright, now we have a valid and non-conflicting filename with a valid extension, so let's save the file. Or attempt to, anyway.
        if ($this->file->save($path . '/' . $fileInfo['filename'] . '.' . $fileInfo['extension']))
        {
            $type = null;
            switch ($fileInfo['extension'])
            {
                case 'jpg':
                case 'gif':
                case 'png':
                case 'jpeg':
                    $type = 'image';
                    break;
                default:
                    $type = 'file';
                    break;
            }

            $query = $this->conn->prepare("INSERT INTO leetup_files (filename, ftype, uptime, upip, hidden, userid, filesize, referer)
                                     VALUES (:filename, :ftype, :uptime, :upip, :hidden, :userid, :filesize, :referer)");
            $query->execute([
                'filename' => $fileInfo['filename'] . '.' . $fileInfo['extension'],
                'ftype' => $type,
                'uptime' => time(),
                'upip' => $_SERVER['REMOTE_ADDR'],
                'hidden' => $hidden,
                'userid' => $this->user['id'],
                'filesize' => $size,
                'referer' => ''
            ]);

            // See if the user's upload rank just increased
            if ($this->user['rank_upLimit'] >= 0)
            {
                $query = $this->conn->prepare("SELECT COUNT(id) AS count FROM leetup_files WHERE userid = ?");
                $query->execute([$this->user['id']]);

                $row = $query->fetch();
                $fileCount = $row['count'];

                $query = $this->conn->prepare("SELECT * FROM leetup_ranks WHERE rank_upLimit >= 0 AND rank_upLimit <= ? ORDER BY rank_upLimit DESC LIMIT 1");
                $query->execute([$fileCount]);

                $row = $query->fetch();
                if ($row['id'] != $this->user['rank'])
                {
                    $query = $this->conn->prepare("UPDATE leetup_users SET rank = ? WHERE id = ?");
                    $query->execute([
                        $row['id'],
                        $this->user['id']
                    ]);
                }
            }

            return $fileInfo['filename'] . '.' . $fileInfo['extension'];
        }

        throw new \Exception("Couldn't save the file.");
    }
}