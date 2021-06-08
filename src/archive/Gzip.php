<?php
/**
 * Created by PhpStorm.
 * User: Professional
 * Date: 08.06.2021
 * Time: 6:46
 */

namespace creater777\backup\archive;

class Gzip extends Tar
{
    function __construct($options)
    {
        parent::__construct($options);
        $this->options['type'] = "gzip";
    }

    function create_gzip()
    {
        if ($this->options['inmemory'] == 0) {
            $pwd = getcwd();
            chdir($this->options['basedir']);
            if ($fp = gzopen($this->options['name'], "wb{$this->options['level']}")) {
                fseek($this->archive, 0);
                while ($temp = fread($this->archive, 1048576))
                    gzwrite($fp, $temp);
                gzclose($fp);
                chdir($pwd);
            } else {
                $this->error[] = "Could not open {$this->options['name']} for writing.";
                chdir($pwd);
                return 0;
            }
        } else
            $this->archive = gzencode($this->archive, $this->options['level']);
        return 1;
    }

    function open_archive()
    {
        return @gzopen($this->options['name'], "rb");
    }
}