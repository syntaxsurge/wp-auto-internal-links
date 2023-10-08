<?php
require_once 'vendor/autoload.php';
require 'Parsedown.php';

use Wamania\Snowball\StemmerFactory;

$parsedown = new Parsedown();

$max_links = 10;
$ignored_words = array("for", "the", "a", "to", "how", "this");
function tokenizeAndStem($text)
{
    global $ignored_words;
    $stemmer = StemmerFactory::create('en');

    // Remove non-ASCII characters
    $text = preg_replace('/[^(\x20-\x7F)]*/','', $text);

    // Split text into tokens based on non-alphanumeric characters, including the apostrophe
    $tokens = preg_split('/[^\w\'-]+/u', mb_strtolower($text));

    $stemmedTokens = array();

    foreach ($tokens as $token) {
        if (!empty($token)) {
            $stemmedTokens[] = $stemmer->stem($token);
        }
    }
    
    return array_diff($stemmedTokens, $ignored_words);
}

function findRelevantLink($old_post_title, $new_content)
{
    $oldTitleStemmed = tokenizeAndStem($old_post_title);
    $contentStemmed = tokenizeAndStem($new_content);

    $matches = array_intersect($contentStemmed, $oldTitleStemmed);

    return $matches;
}
function replace_text_with_anchor($old_paragraph, $linked_paragraph)
{
    // Find the anchor tags and their inner text in $linked_paragraph
    preg_match_all('/<a\s+(?:[^\'"]*|\'[^\']*\'|"[^"]*")*?\bhref=([\'"])(.*?)\1[^>]*>(.*?)<\/a>/i', $linked_paragraph, $matches, PREG_SET_ORDER);
    if (count($matches) >= 1) {
        $url1 = $matches[0][2];
        $inner_text1 = $matches[0][3];
        
        // If there is only one anchor link, just return the link
        if (count($matches) == 1) {
            $replacement = '<a href="' . $url1 . '">' . $inner_text1 . '</a>';
        } else {
            $url2 = $matches[1][2];
            $inner_text2 = $matches[1][3];
            
            // Calculate the number of words between the two anchor links
            $first_anchor_start = strpos($linked_paragraph, '<a');
            $first_anchor_end = strpos($linked_paragraph, '</a>', $first_anchor_start) + 4;
            $second_anchor_start = strpos($linked_paragraph, '<a', $first_anchor_end);
            $second_anchor_end = strpos($linked_paragraph, '</a>', $second_anchor_start) + 4;
            $word_distance = 0;
            if ($second_anchor_start > $first_anchor_end) {
                $inner_text1_end = strpos($linked_paragraph, '</a>', $first_anchor_start) + 4;
                $first_link_words = substr($linked_paragraph, $inner_text1_end, $second_anchor_start - $inner_text1_end);
                $word_distance = str_word_count($first_link_words);
            }
            
            // Combine the anchor links only if the word distance is less than 2
            if ($word_distance < 3) {
                $combined_inner_text = $inner_text1 . ' ' . substr($linked_paragraph, $first_anchor_end, $second_anchor_start - $first_anchor_end) . ' ' . $inner_text2;
                $replacement = '<a href="' . $url1 . '">' . $combined_inner_text . '</a>';
            } else {
                $replacement = '<a href="' . $url1 . '">' . $inner_text1 . '</a>';
            }
        }
        
        // Replace only the first occurrence of the inner text of the first anchor link in $old_paragraph with the anchored link text
        $combined_paragraph = preg_replace('/'. preg_quote($inner_text1, '/') .'/', $replacement, $old_paragraph, 1);

        return $combined_paragraph;
    } else {
        // If no match is found, return the $old_paragraph unchanged
        return $old_paragraph;
    }
}

function innerHTML(DOMNode $element)
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;

    $dom->appendChild($dom->importNode($element, true));

    // Get the content as a string and convert it to UTF-8
    $content = $dom->saveXML($dom->documentElement, LIBXML_NOEMPTYTAG);

    return utf8_decode(trim($content));
}

function trimIgnoredWords($words, $ignored_words) {
    while (in_array(strtolower(end($words)), $ignored_words)) {
        array_pop($words);
    }
    while (in_array(strtolower(reset($words)), $ignored_words)) {
        array_shift($words);
    }

    return $words;
}

function count_internal_links_regex($content) {
    $pattern = '/<a\s+(?:[^>]*?\s+)?href=["\']https?:\/\/(?:www\.)?techtrim\.tech[^"\'\s>]*["\']/i';

    preg_match_all($pattern, $content, $matches);

    return count($matches[0]);
}
function insert_links($new_content, $old_post_title, $old_post_url)
{
    global $ignored_words, $max_links;
    $anchor_link_count = count_internal_links_regex($new_content);
    if($anchor_link_count >= $max_links){
        return;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($new_content);

    $paragraphs = $dom->getElementsByTagName('p');
    if ($paragraphs->length == 0) {
        $paragraphs = $dom->getElementsByTagName('span');
    }
    foreach ($paragraphs as $paragraph) {
        $paragraphContent = $paragraph->textContent;

        // Skip paragraph if it already contains an anchor link
        if (preg_match('/<a\s.*<\/a>/', innerHTML($paragraph))) {
            continue;
        }

        $matchFound = false; // initialize flag variable
        $matches = findRelevantLink($old_post_title, $paragraphContent);
        if (!empty($matches)) {
            // Remove non-ASCII characters
            $paragraphWords = preg_split('/[^\w\'-]+/u', $paragraphContent);
            $paragraphWords = preg_replace('/[^(\x20-\x7F)]*/','', $paragraphWords);
            $linkedParagraph = '';
            $skip = 0;
            $previousWord = '';
            $counter = 0;
            foreach ($paragraphWords as $index => $word) {
                if ($skip > 0) {
                    $skip--;
                    continue;
                }
                
                if (isset($matches[$index]) && $previousWord != $word || in_array($word, $ignored_words)) {
                    $phrase = $word;
                    $nextIndex = $index + 1;
                    $phraseLength = 1;

                    while (isset($matches[$nextIndex]) || ( isset($matches[$nextIndex + 1]) ? in_array($paragraphWords[$nextIndex], $ignored_words) : (isset($matches[$nextIndex + 2]) ? in_array($paragraphWords[$nextIndex], $ignored_words) : '' ))) {
                        
                        $phrase .= ' ' . $paragraphWords[$nextIndex];
                        $skip++;
                        $nextIndex++;
                        $phraseLength++;

                    }
                    
                    // Check if the phrase has a period in the original content
                    $originalPhrase = implode(" ", array_slice(explode(" ", $paragraphContent), $index, $phraseLength));
                    
                    if (strpos($originalPhrase, ".") === false) {
                        if ($phraseLength > 1) {
                            $words = explode(" ", $phrase);
                            $words = trimIgnoredWords($words, $ignored_words);
                            $phrase = implode(' ', $words);

                            if (count($words) > 1) {
                                $linkedParagraph .= "<a href='$old_post_url'>$phrase</a>";
                                $matchFound = true;
                            }
                        } else {
                            $linkedParagraph .= $word;
                        }
                    } else {
                        $linkedParagraph .= $word;
                    }
                } else {
                    $linkedParagraph .= $word;
                }

                $previousWord = $word;

                if ($index < count($paragraphWords) - 1) {
                    $linkedParagraph .= ' ';
                }

                $counter++;
            }
            $old_paragraph = innerHTML($paragraph);
            $combined_paragraph = replace_text_with_anchor($old_paragraph, $linkedParagraph);
            $new_content = str_replace($old_paragraph, $combined_paragraph, $new_content);
        }

        if ($matchFound) {
            break; // stop iteration of paragraphs if a match is found
        }
    }

    return $new_content;
}


function fix_html_errors($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    // Remove empty nodes
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[not(*) and not(normalize-space())]');
    foreach ($nodes as $node) {
        $node->parentNode->removeChild($node);
    }

    // Fix unclosed tags
    $fixed_html = '';
    foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $node) {
        $fixed_html .= $dom->saveHTML($node);
    }

    return $fixed_html;
}

$new_content = <<<EOL
Believe-you can, and you're halfway there This quote encourages belief in oneself and the power of positive thinking to achieve what you set out to do. It is a reminder that believing in yourself is essential to overcoming obstacles.

EOL;

$new_content = $parsedown->text($new_content); 

$old_post_title = "2023's Top 50 Encouraging Quotes about Work.";
$old_post_url = "https://leaveadvice.com/work-positive-quotes/";

$linkedContent = insert_links($new_content, $old_post_title, $old_post_url);

echo $linkedContent;