#!/usr/bin/php
<?

$book = array();
$content = "";
$isbn = "";

function startElement($parser, $name, $attrs) {
}

function endElement($parser, $name) {
  global $isbn, $content;
  if ($name == "ISBN") {
    $isbn = trim($content);
  }
  if ($name == "TOC") {
    if ($isbn != "") {
       $content = trim($content);
       $filename = "isbn" . normalizeISBN($isbn) . ".txt";
       $file = fopen($filename, "c");
       fwrite($file, $content);
       fclose($file);
    }
  }
  if ($name == "BOOK") {
    $isbn = "";
  }
  $content = "";
}

function defaultHandler($parser, $data) {
  global $content;
  $content .= $data;
}

function normalizeISBN($isbn) {
  $isbn = str_replace("-", "", $isbn);
  return $isbn;
}

$file = "toc.xml";

$xml_parser = xml_parser_create();
xml_set_element_handler($xml_parser, "startElement", "endElement");
xml_set_default_handler($xml_parser, "defaultHandler");
if (!($fp = fopen($file, "r"))) {
    die("could not open XML input");
}

while ($data = fread($fp, 128)) {
    if (!xml_parse($xml_parser, $data, feof($fp))) {
        die(sprintf("XML error: %s at line %d",
                    xml_error_string(xml_get_error_code($xml_parser)),
                    xml_get_current_line_number($xml_parser)));
    }
}
xml_parser_free($xml_parser);

print_r($book);

?>
