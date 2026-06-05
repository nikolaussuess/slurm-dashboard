<?php

/**
 * Insert text into HTML page templates.
 */
class TemplateLoader {
    private string $contents;
    private const TEMPLATE_DIR = __DIR__ . '/templates/';

    /**
     * @param string $filename Filename of template html file
     */
    function __construct(string $filename){
        $this->contents = file_get_contents(self::TEMPLATE_DIR . basename($filename));
        if ($this->contents === FALSE)
            throw new \exceptions\ConfigurationError(
                "Internal server error.",
                "Template file not found: file_get_contents failed for: " . self::TEMPLATE_DIR . basename($filename)
            );
    }

    /**
     * Replates {{$name}} by $value.
     * @param string $name Name of parameter.
     * @param mixed $value Value to be inserted into the HTML file.
     * @return static
     */
    function setParam(string $name, mixed $value): static {

        if( $value === NULL ){
            error_log("TemplateLoader: value of name '$name' was NULL at " . (function(){ob_start();debug_print_backtrace();return ob_get_clean();})());
            $value = '';
        }

        $this->contents = str_replace('{{' . strtoupper($name) . '}}', (string)$value, $this->contents);
        return $this;
    }

    /**
     * @return false|string The result text or FALSE if the file was not found.
     */
    function build(): bool|string {
        return $this->contents;
    }
}