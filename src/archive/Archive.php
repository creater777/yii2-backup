<?
namespace creater777\backup\archive;

// +--------------------------------------------------
// | Open Source Contributions
// +--------------------------------------------------
/*-------------------------------------------------
| TAR/GZIP/BZIP2/ZIP ARCHIVE CLASSES 2.1
| By Devin Doucette
| Copyright (c) 2005 Devin Doucette
| Email: darksnoopy@shaw.ca
+--------------------------------------------------
| Email bugs/suggestions to darksnoopy@shaw.ca
+--------------------------------------------------
| This script has been created and released under
| the GNU GPL and is free to use and redistribute
| only if this copyright statement is not removed
+--------------------------------------------------*/

class Archive
{
    protected $options = [
        'basedir' => ".",
        'prepend' => "",
        'inmemory' => 0,
        'overwrite' => 0,
        'recurse' => 1,
        'storepaths' => 1,
        'followlinks' => 0,
        'level' => 3,
        'method' => 1,
        'sfx' => "",
        'type' => "",
        'comment' => ""
    ];

    protected $files = [];
    public $exclude = [];
    public $storeonly = [];
    public $error = [];

    public function __construct($options)
    {
        $this->setOptions($options);
    }

    private function getName2($name = null, $current){
        return $name2 = is_null($name) ?
            $this->options['prepend'] . preg_replace("/(\.+\/+)+/", "",
                ($this->options['storepaths'] == 0 && strstr($current, "/"))
                    ? substr($current, strrpos($current, "/") + 1)
                    : $current)
            : $name;

    }

    public function getName(){
        return $this->options['name'];
    }

    public function setOptions($options)
    {
        foreach ($options as $key => $value)
            $this->options[$key] = $value;
        if (!empty($this->options['basedir'])) {
            $this->options['basedir'] = str_replace("\\", "/", $this->options['basedir']);
            $this->options['basedir'] = preg_replace("/\/+/", "/", $this->options['basedir']);
            $this->options['basedir'] = preg_replace("/\/$/", "", $this->options['basedir']);
        }
        if (!empty($this->options['name'])) {
            $this->options['name'] = str_replace("\\", "/", $this->options['name']);
            $this->options['name'] = preg_replace("/\/+/", "/", $this->options['name']);
        }
        if (!empty($this->options['prepend'])) {
            $this->options['prepend'] = str_replace("\\", "/", $this->options['prepend']);
            $this->options['prepend'] = preg_replace("/^(\.*\/+)+/", "", $this->options['prepend']);
            $this->options['prepend'] = preg_replace("/\/+/", "/", $this->options['prepend']);
            $this->options['prepend'] = preg_replace("/\/$/", "", $this->options['prepend']) . "/";
        }
    }

    public function createArchive()
    {
        $this->makeList();
        if ($this->options['inmemory'] == 0) {
            $pwd = getcwd();
            chdir($this->options['basedir']);
            if ($this->options['overwrite'] == 0 && file_exists($this->options['name'])) {
                $this->error[] = "File {$this->options['name']} already exists.";
                chdir($pwd);
                return 0;
            } else if ($this->archive = @fopen($this->options['name'], "wb+")) {
                chdir($pwd);
            } else {
                $this->error[] = "Could not open {$this->options['name']} for writing.";
                chdir($pwd);
                return 0;
            }
        } else {
            $this->archive = "";
        }
        switch ($this->options['type']) {
            case "zip":
                if (!$this->create_zip()) {
                    $this->error[] = "Could not create zip file.";
                    return 0;
                }
                break;
            case "bzip":
                if (!$this->create_tar()) {
                    $this->error[] = "Could not create tar file.";
                    return 0;
                }
                if (!$this->create_bzip()) {
                    $this->error[] = "Could not create bzip2 file.";
                    return 0;
                }
                break;
            case "gzip":
                if (!$this->create_tar()) {
                    $this->error[] = "Could not create tar file.";
                    return 0;
                }
                if (!$this->create_gzip()) {
                    $this->error[] = "Could not create gzip file.";
                    return 0;
                }
                break;
            case "tar":
                if (!$this->create_tar()) {
                    $this->error[] = "Could not create tar file.";
                    return 0;
                }
        }
        if ($this->options['inmemory'] == 0) {
            fclose($this->archive);
        }
    }

    public function addData($data)
    {
        if ($this->options['inmemory'] == 0)
            fwrite($this->archive, $data);
        else
            $this->archive .= $data;
    }

    public function makeList()
    {
        if (!empty($this->exclude))
            foreach ($this->files as $key => $value)
                foreach ($this->exclude as $current)
                    if ($value['name'] == $current['name'])
                        unset($this->files[$key]);
        if (!empty($this->storeonly))
            foreach ($this->files as $key => $value)
                foreach ($this->storeonly as $current)
                    if ($value['name'] == $current['name'])
                        $this->files[$key]['method'] = 0;
        unset($this->exclude, $this->storeonly);
    }

    public function addFiles($list, $name = null)
    {
        $temp = $this->listFiles($list, $name);
        foreach ($temp as $current)
            $this->files[] = $current;
    }

    public function excludeFiles($list)
    {
        $temp = $this->listFiles($list);
        foreach ($temp as $current)
            $this->exclude[] = $current;
    }

    public function storeFiles($list)
    {
        $temp = $this->listАiles($list);
        foreach ($temp as $current)
            $this->storeonly[] = $current;
    }

    public function listFiles($list, $name = null)
    {
        if (!is_array($list)) {
            $temp = $list;
            $list = array(
                $temp
            );
            unset($temp);
        }
        $files = array();
        $pwd = getcwd();
        chdir($this->options['basedir']);
        foreach ($list as $current) {
            $current = str_replace("\\", "/", $current);
            $current = preg_replace("/\/+/", "/", $current);
            $current = preg_replace("/\/$/", "", $current);
            if (strstr($current, "*")) {
                $regex = preg_replace("/([\\\^\$\.\[\]\|\(\)\?\+\{\}\/])/", "\\\\\\1", $current);
                $regex = str_replace("*", ".*", $regex);
                $dir = strstr($current, "/") ? substr($current, 0, strrpos($current, "/")) : ".";
                $temp = $this->parse_dir($dir);
                foreach ($temp as $current2)
                    if (preg_match("/^{$regex}$/i", $current2['name']))
                        $files[] = $current2;
                unset($regex, $dir, $temp, $current);
            } else if (@is_dir($current)) {
                $temp = $this->parse_dir($current);
                foreach ($temp as $file)
                    $files[] = $file;
                unset($temp, $file);
            } else if (@file_exists($current))
                $files[] = array(
                    'name' => $current,
                    'name2' => $this->getName2($name, $current),
                    'type' => @is_link($current) && $this->options['followlinks'] == 0 ? 2 : 0,
                    'ext' => substr($current, strrpos($current, ".")),
                    'stat' => stat($current)
                );
        }
        chdir($pwd);
        unset($current, $pwd);
        return $files;
    }

    public function parseDir($dirname, $name = null)
    {
        $files = array();
        if ($this->options['storepaths'] == 1 && !preg_match("/^(\.+\/*)+$/", $dirname)) {
            $files = array(
                array(
                    'name' => $dirname,
                    'name2' => $this->getName2($name),
                    'type' => 5,
                    'stat' => stat($dirname)
                )
            );
        }
        if ($dir = @opendir($dirname)) {
            while (($file = @readdir($dir)) !== false) {
                $fullname = $dirname . "/" . $file;
                if ($file == "." || $file == "..")
                    continue;
                else if (@is_dir($fullname)) {
                    if (empty($this->options['recurse']))
                        continue;
                    $temp = $this->parse_dir($fullname);
                    foreach ($temp as $file2)
                        $files[] = $file2;
                } else if (@file_exists($fullname))
                    $files[] = array(
                        'name' => $fullname,
                        'name2' => $this->options['prepend'] . preg_replace("/(\.+\/+)+/", "", ($this->options['storepaths'] == 0 && strstr($fullname, "/")) ? substr($fullname, strrpos($fullname, "/") + 1) : $fullname),
                        'type' => @is_link($fullname) && $this->options['followlinks'] == 0 ? 2 : 0,
                        'ext' => substr($file, strrpos($file, ".")),
                        'stat' => stat($fullname)
                    );
            }
            @closedir($dir);
        }
        return $files;
    }

    public function sortFiles($a, $b)
    {
        if ($a['type'] != $b['type'])
            if ($a['type'] == 5 || $b['type'] == 2)
                return -1;
            else if ($a['type'] == 2 || $b['type'] == 5)
                return 1;
            else if ($a['type'] == 5)
                return strcmp(strtolower($a['name']), strtolower($b['name']));
            else if ($a['ext'] != $b['ext'])
                return strcmp($a['ext'], $b['ext']);
            else if ($a['stat'][7] != $b['stat'][7])
                return $a['stat'][7] > $b['stat'][7] ? -1 : 1;
            else
                return strcmp(strtolower($a['name']), strtolower($b['name']));
        return 0;
    }

    public function downloadFile()
    {
        if ($this->options['inmemory'] == 0) {
            $this->error[] = "Can only use download_file() if archive is in memory. Redirect to file otherwise, it is faster.";
            return;
        }
        switch ($this->options['type']) {
            case "zip":
                header("Content-Type: application/zip");
                break;
            case "bzip":
                header("Content-Type: application/x-bzip2");
                break;
            case "gzip":
                header("Content-Type: application/x-gzip");
                break;
            case "tar":
                header("Content-Type: application/x-tar");
        }
        $header = "Content-Disposition: attachment; filename=\"";
        $header .= strstr($this->options['name'], "/") ? substr($this->options['name'], strrpos($this->options['name'], "/") + 1) : $this->options['name'];
        $header .= "\"";
        header($header);
        header("Content-Length: " . strlen($this->archive));
        header("Content-Transfer-Encoding: binary");
        header("Cache-Control: no-cache, must-revalidate, max-age=60");
        header("Expires: Sat, 01 Jan 2000 12:00:00 GMT");
        print($this->archive);
        exit();
    }
}