<?php
/**
 * Created by PhpStorm.
 * User: Professional
 * Date: 08.06.2021
 * Time: 6:46
 */

namespace creater777\backup\archive;

class Tar extends Archive
{
    function __construct($options)
    {
        parent::__construct($options);
        $this->options['type'] = "tar";
    }

    function create_tar()
    {
        $pwd = getcwd();
        chdir($this->options['basedir']);
        foreach ($this->files as $current) {
            if ($current['name'] == $this->options['name'])
                continue;
            if (strlen($current['name2']) > 99) {
                $path = substr($current['name2'], 0, strpos($current['name2'], "/", strlen($current['name2']) - 100) + 1);
                $current['name2'] = substr($current['name2'], strlen($path));
                if (strlen($path) > 154 || strlen($current['name2']) > 99) {
                    $this->error[] = "Could not add {$path}{$current['name2']} to archive because the filename is too long.";
                    continue;
                }
            }
            $block = pack("a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12", $current['name2'], sprintf("%07o", $current['stat'][2]), sprintf("%07o", $current['stat'][4]), sprintf("%07o", $current['stat'][5]), sprintf("%011o", $current['type'] == 2 ? 0 : $current['stat'][7]), sprintf("%011o", $current['stat'][9]), "        ", $current['type'], $current['type'] == 2 ? @readlink($current['name']) : "", "ustar ", " ", "Unknown", "Unknown", "", "", !empty($path) ? $path : "", "");
            $checksum = 0;
            for ($i = 0; $i < 512; $i++)
                $checksum += ord(substr($block, $i, 1));
            $checksum = pack("a8", sprintf("%07o", $checksum));
            $block = substr_replace($block, $checksum, 148, 8);
            if ($current['type'] == 2 || $current['stat'][7] == 0)
                $this->addData($block);
            else if ($fp = @fopen($current['name'], "rb")) {
                $this->addData($block);
                while ($temp = fread($fp, 1048576))
                    $this->addData($temp);
                if ($current['stat'][7] % 512 > 0) {
                    $temp = "";
                    for ($i = 0; $i < 512 - $current['stat'][7] % 512; $i++)
                        $temp .= "\0";
                    $this->addData($temp);
                }
                fclose($fp);
            } else
                $this->error[] = "Could not open file {$current['name']} for reading. It was not added.";
        }
        $this->addData(pack("a1024", ""));
        chdir($pwd);
        return 1;
    }

    function extract_files()
    {
        $pwd = getcwd();
        chdir($this->options['basedir']);
        if ($fp = $this->open_archive()) {
            if ($this->options['inmemory'] == 1)
                $this->files = array();
            while ($block = fread($fp, 512)) {
                $temp = unpack("a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp", $block);
                $file = array(
                    'name' => $temp['prefix'] . $temp['name'],
                    'stat' => array(
                        2 => $temp['mode'],
                        4 => octdec($temp['uid']),
                        5 => octdec($temp['gid']),
                        7 => octdec($temp['size']),
                        9 => octdec($temp['mtime'])
                    ),
                    'checksum' => octdec($temp['checksum']),
                    'type' => $temp['type'],
                    'magic' => $temp['magic']
                );
                if ($file['checksum'] == 0x00000000)
                    break;
                else if (substr($file['magic'], 0, 5) != "ustar") {
                    $this->error[] = "This script does not support extracting this type of tar file.";
                    break;
                }
                $block = substr_replace($block, "        ", 148, 8);
                $checksum = 0;
                for ($i = 0; $i < 512; $i++)
                    $checksum += ord(substr($block, $i, 1));
                if ($file['checksum'] != $checksum)
                    $this->error[] = "Could not extract from {$this->options['name']}, it is corrupt.";
                if ($this->options['inmemory'] == 1) {
                    $file['data'] = fread($fp, $file['stat'][7]);
                    fread($fp, (512 - $file['stat'][7] % 512) == 512 ? 0 : (512 - $file['stat'][7] % 512));
                    unset($file['checksum'], $file['magic']);
                    $this->files[] = $file;
                } else if ($file['type'] == 5) {
                    if (!is_dir($file['name']))
                        mkdir(fs_encode($file['name']), $file['stat'][2]);
                } else if ($this->options['overwrite'] == 0 && file_exists($file['name'])) {
                    $this->error[] = "{$file['name']} already exists.";
                    continue;
                } else if ($file['type'] == 2) {
                    symlink_phpfm($temp['symlink'], $file['name']);
                    chmod($file['name'], $file['stat'][2]);
                } else if ($new = @fopen($file['name'], "wb")) {
                    fwrite($new, fread($fp, $file['stat'][7]));
                    fread($fp, (512 - $file['stat'][7] % 512) == 512 ? 0 : (512 - $file['stat'][7] % 512));
                    fclose($new);
                    chmod($file['name'], $file['stat'][2]);
                } else {
                    $this->error[] = "Could not open {$file['name']} for writing.";
                    continue;
                }
                chown($file['name'], $file['stat'][4]);
                chgrp($file['name'], $file['stat'][5]);
                touch($file['name'], $file['stat'][9]);
                unset($file);
            }
        } else
            $this->error[] = "Could not open file {$this->options['name']}";
        chdir($pwd);
    }

    function open_archive()
    {
        return @fopen($this->options['name'], "rb");
    }
}