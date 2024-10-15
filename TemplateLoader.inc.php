<?php

/**
 * Insert text into HTML page templates.
 */
class TemplateLoader {

    /**
     * @param $filename string Filename of template html file
     * @param $dirname Directory where the file is located. Defaults to /templates
     */
    function __construct($filename, $dirname = 'templates/'){
        $this->contents = file_get_contents($dirname . $filename);
    }

    /**
     * Replates {{$name}} by $value.
     * @param $name string Name of parameter.
     * @param $value string Value to be inserted into the HTML file.
     * @return $this
     */
    function setParam($name, $value){
        $this->contents = preg_replace("/{{".$name."}}/i", $value, $this->contents);
        return $this;
    }

    /**
     * @return false|string The result text or FALSE if the file was not found.
     */
    function build(){
        return $this->contents;
    }
}