<?php

/**
 * Insert text into HTML page templates.
 */
class TemplateLoader {
    private string $contents;

    /**
     * @param $filename string Filename of template html file
     * @param $dirname string Directory where the file is located. Defaults to /templates
     */
    function __construct(string $filename, string $dirname = __DIR__ . '/templates/'){
        $this->contents = file_get_contents($dirname . $filename);
    }

    /**
     * Replates {{$name}} by $value.
     * @param $name string Name of parameter.
     * @param $value mixed Value to be inserted into the HTML file.
     * @return static $this
     */
    function setParam(string $name, mixed $value): static {

        if( $value === NULL ){
            error_log("TemplateLoader: value of name '$name' was NULL at " . (function(){ob_start();debug_print_backtrace();return ob_get_clean();})());
            $value = '';
        }

        $this->contents = preg_replace("/{{".$name."}}/i", $value, $this->contents);
        return $this;
    }

    /**
     * @return false|string The result text or FALSE if the file was not found.
     */
    function build(): bool|string {
        return $this->contents;
    }
}