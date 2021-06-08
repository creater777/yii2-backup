<?php
/**
 * Created by PhpStorm.
 * User: Professional
 * Date: 08.06.2021
 * Time: 6:47
 */

namespace creater777\backup\archive;

class Bzip2 extends Tar
{
    function __construct($options)
    {
        parent::__construct($options);
        $this->options['type'] = "bzip";
    }

    function create_bzip()
    {
        if ($this->options['inmemory'] == 0) {
            $pwd = getcwd();
            chdir($this->options['basedir']);
            if ($fp = bzopen($this->options['name'], "wb")) {
                fseek($this->archive, 0);
                while ($temp = fread($this->archive, 1048576))
                    bzwrite($fp, $temp);
                bzclose($fp);
                chdir($pwd);
            } else {
                $this->error[] = "Could not open {$this->options['name']} for writing.";
                chdir($pwd);
                return 0;
            }
        } else
            $this->archive = bzcompress($this->archive, $this->options['level']);
        return 1;
    }

    function open_archive()
    {
        return @bzopen($this->options['name'], "rb");
    }
}