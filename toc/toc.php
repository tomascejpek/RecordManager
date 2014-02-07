#!/usr/bin/php
<?

require '../classes/MetadataUtils.php';

$book = array();
$content = "";
$isbn = "";
$nbn = "";

function stripInvalidXmlChars($in) {
  $out = "";
  $current;
  if (empty($in)) {
    return "";
  }
  $length = strlen($in);
  for ( $i = 0; $i < $length; $i++) {
    $current = ord($in{$i});
    $valid = ($current == 0x9) || ($current == 0xA) || ($current == 0xD) ||
      (($current >= 0x20) && ($current <= 0xD7FF)) ||
      (($current >= 0xE000) && ($current <= 0xFFFD)) ||
      (($current >= 0x10000) && ($current <= 0x10FFFF));
    $out .= ($valid)? chr($current) : ' ';
  }
  return $out;
}


function startElement($parser, $name, $attrs) {
}

function endElement($parser, $name) {
  global $isbn, $content, $nbn;
  if ($name == "ISBN") {
    $isbn = trim($content);
  }
  if ($name == "NBN") {
    $nbn = trim($content);
  }
  if ($name == "TOC") {
    if ($nbn != "") {
       $content = trim($content);
       $filename = "cnb_" . normalizeNBN($nbn) . ".txt";
       $file = fopen($filename, "c");
       fwrite($file, $content);
       fclose($file);
    } else if ($isbn != "") {
       $content = trim($content);
       $filename = "isbn_" . normalizeISBN($isbn) . ".txt";
       $file = fopen($filename, "c");
       fwrite($file, $content);
       fclose($file);
    }
  }
  if ($name == "BOOK") {
    $isbn = "";
    $nbn = "";
  }
  $content = "";
}

function defaultHandler($parser, $data) {
  global $content;
  $content .= $data;
}

function normalizeISBN($isbn) {
  $isbn = str_replace("-", "", $isbn);
  if (strlen($isbn) == 10) {
    $isbn = MetadataUtils::isbn10to13($isbn);
  }
  return $isbn;
}

function normalizeNBN($nbn) {
  $nbn = str_replace("/", "", $nbn);
  return $nbn;
}

$file = "toc.xml";

$xml_parser = xml_parser_create();
xml_set_element_handler($xml_parser, "startElement", "endElement");
xml_set_default_handler($xml_parser, "defaultHandler");
if (!($fp = fopen($file, "r"))) {
    die("could not open XML input");
}

while ($data = fread($fp, 128)) {
    $data = stripInvalidXmlChars($data);
    if (!xml_parse($xml_parser, $data, feof($fp))) {
        die(sprintf("XML error: %s at line %d",
                    xml_error_string(xml_get_error_code($xml_parser)),
                    xml_get_current_line_number($xml_parser)));
    }
}
xml_parser_free($xml_parser);

?>
