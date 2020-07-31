<?php
namespace Cscheide\ArticleExtractor;

use Goose\Client as GooseClient;

use GuzzleHttp\Client as GuzzleClient;

use andreskrey\Readability\Readability;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;

use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\HtmlNode;
use PHPHtmlParser\Dom\TextNode;

use DetectLanguage\DetectLanguage;

class ArticleExtractor
{

    // Debug flag - set to true for convenience during development
    private $debug = false;

    // Valid root elements we want to search for
    private $valid_root_elements = ['body', 'form', 'main', 'div', 'ul', 'li', 'table', 'span', 'section', 'article', 'main'];

    // Elements we want to place a space in front of when converting to text
    private $space_elements = ['p', 'li'];

    // API key for the remote detection service
    private $api_key = null;

    // User agent to override
    private $user_agent = null;

    public function __construct($api_key = null, $user_agent = null)
    {
        $this->api_key = $api_key;
        $this->user_agent = $user_agent;
    }

    /**
     * Provided for backward compatibility.
     */
    public function getArticleText($url)
    {
        return $this->processURL($url);
    }

    /**
     * The only public function for this class. processURL returns the best guess
     * of the human readable part of a URL, as well as additional meta data
     * associated with the parsing.
     *
     * Returns an array with the following information:
     *
     * [
     *	  title => (the title of the article)
     *	  text => (the human readable piece of the article)
     *	  parse_method => (the internal processing method used to parse the article, "goose", "custom", "readability"
     *	  language => (the ISO 639-1 code detected for the language)
     *	  language_method => (the way the language was detected)
     * ]
     *
     * This processing will attempt to use the following methods in this order:
     *   1. Readability
     *   2. Goose
     *   3. Goose with some additional custom processing
     *
     */
    public function processURL($url)
    {

        // Check for redirects first
        $url = $this->checkForRedirects($url);

        $this->log_debug("Attempting to parse " . $url);

        // First attempt to parse the URL into the structure we want
        $results = $this->parseViaReadability($url);

        // If we don't see what we want, try our other method
        if ($results['text'] == null)
        {
            $results = $this->parseViaGooseOrCustom($url);
        }

        // Add the resultant URL after redirects
        $results['result_url'] = $url;

        // If we still don't havewhat we want, return what we have
        if ($results['text'] == null)
        {
            $results['language'] = null;
            $results['language_method'] = null;
            unset($results['html']); // remove raw HTML before returning it
            return $results;
        }

        // Otherwise, continue on...
        // Implement check in HTML to determine if the language is specified somewhere
        if ($lang_detect = $this->checkHTMLForLanguageHint($results['html']))
        {
            $results['language_method'] = "html";
            $results['language'] = $lang_detect;
            $this->log_debug("Language was detected as " . $results['language'] . " from HTML");
        }

        $this->log_debug("--------- PRE UTF 8 CLEANING -------------------------------------");
        $this->log_debug("title: " . $results['title']);
        $this->log_debug("text: " . $results['text']);
        $this->log_debug("------------------------------------------------------------------");

        // Convert items to UTF-8
        $results['title'] = $this->shiftEncodingToUTF8($results['title']);
        $results['text'] = $this->shiftEncodingToUTF8($results['text']);

        // If we've got some text, we still don't have a language, and we're configured with an API key...
        if ($results['text'] != null && !isset($results['language']))
        {

            // If we have an API key
            if ($this->api_key != null)
            {

                // Then use the service to detect the language
                $results['language_method'] = "service";
                $results['language'] = $this->identifyLanguage(mb_substr($results['text'], 0, 100));
                $this->log_debug("Language was detected as  " . $results['language'] . " from service");
            }
            // Otherwise skip
            else
            {
                $this->log_debug("Skipping remote language detection service check");
                $results['language_method'] = null;
                $results['language'] = null;
            }
        }

        $this->log_debug("text: " . $results['text']);
        $this->log_debug("title: " . $results['title']);
        $this->log_debug("language: " . $results['language']);
        $this->log_debug("parse_method: " . $results['parse_method']);
        $this->log_debug("language_method: " . $results['language_method']);
        $this->log_debug("result_url: " . $results['result_url']);

        unset($results['html']); // remove raw HTML before returning it
        return $results;

    }

    /**
     * Attempts to parse via the Readability libary aReturns the following array.
     * [
     *    'method' => "readability"
     *    'title' => <the title of the article>
     *    'text' => <the cleaned text of the article> | null
     *    'html' => <the raw HTML of the article>
     * ]
     *
     * Parsing can be considered unavailable if 'text' is returned as null
     */
    private function parseViaReadability($url)
    {

        $text = null;
        $title = null;
        $method = "readability";

        $readability = new Readability(new Configuration(['SummonCthulhu' => true]));

        try
        {
            if ($this->user_agent != null)
            {
                $this->log_debug("Manually setting user agent for file_get_contents to " . $this->user_agent);
                $context = stream_context_create(array(
                    'http' => array(
                        'user_agent' => $this->user_agent
                    )
                ));
                $html = file_get_contents($url, false, $context);
            }
            else
            {
                $html = file_get_contents($url);
            }

            $readability->parse($html);
            $title = $readability->getTitle();
            $text = $readability->getContent();

            // Replace all <h*> and </h*> tags with newlines
            $text = preg_replace('/<h[1-6]>/', "\n", $text);
            $text = preg_replace('/<\/h[1-6]>/', "\n", $text);
            $text = preg_replace('/<p>/', "\n", $text);
            $text = preg_replace('/<\/p>/', "\n", $text);

            $text = strip_tags($text); // Remove all HTML tags
            $text = html_entity_decode($text); // Make sure we have no HTML entities left over
            $text = str_replace("\t", " ", $text); // Replace tabs with spaces
            $text = preg_replace('/ {2,}/', ' ', $text); // Remove multiple spaces
            $text = str_replace("\r", "\n", $text); // convert carriage returns to newlines
            $text = preg_replace("/(\n)+/", "$1", $text); // remove excessive line returns
            

            $markdown = $readability->getContent();

            // Replace all <h*> and </h*> tags with newlines
            $markdown = str_replace('<h1>', '#', $text);
            $markdown = str_replace('<h2>', '##', $text);
            $markdown = str_replace('<h3>', '###', $text);
            $markdown = str_replace('<h4>', '####', $text);
            $markdown = str_replace('<h5>', '#####', $text);
            $markdown = str_replace('<h6>', '######', $text);

            $markdown = preg_replace('/<\/h[1-6]>/', "\n", $text);
            $markdown = str_replace('<p>', '', $text);
            $markdown = preg_replace('/<\/p>/', "\n", $text);

            $markdown = strip_tags($text); // Remove all HTML tags
            $markdown = html_entity_decode($text); // Make sure we have no HTML entities left over
            $markdown = str_replace("\t", " ", $text); // Replace tabs with spaces
            $markdown = preg_replace('/ {2,}/', ' ', $text); // Remove multiple spaces
            $markdown = str_replace("\r", "\n", $text); // convert carriage returns to newlines
            $markdown = preg_replace("/(\n)+/", "$1", $text); // remove excessive line returns
            
        }
        catch(ParseException $e)
        {
            $this->log_debug('parseViaReadability: Error processing text', $e->getMessage());
        }
        return ['parse_method' => $method, 'title' => $title, 'text' => $text, 'markdown' => $markdown, 'html' => $html];
    }

    /**
     * Attempts to parse via the Goose libary and our custom processing. Returns the
     * following array.
     * [
     *    'method' => "goose" | "custom" | null
     *    'title' => <the title of the article>
     *    'text' => <the cleaned text of the article> | null
     *    'html' => <the raw HTML of the article>
     * ]
     *
     * Parsing can be considered unavailable if 'text' is returned as null
     */
    private function parseViaGooseOrCustom($url)
    {

        $text = null;
        $method = "goose";
        $title = null;
        $html = null;

        $this->log_debug("Parsing via: goose method");

        // Try to get the article using Goose first
        $goose = new GooseClient(['image_fetch_best' => false]);

        try
        {
            $article = $goose->extractContent($url);
            $title = $article->getTitle();
            $html = $article->getRawHtml();

            // If Goose failed
            if ($article->getCleanedArticleText() == null)
            {

                // Get the HTML from goose
                $html_string = $article->getRawHtml();

                //$this->log_debug("---- RAW HTML -----------------------------------------------------------------------------------");
                //$this->log_debug($html_string);
                //$this->log_debug("-------------------------------------------------------------------------------------------------");
                // Ok then try it a different way
                $dom = new Dom;
                $dom->load($html_string, ['whitespaceTextNode' => false]);

                // First, just completely remove the items we don't even care about
                $nodesToRemove = $dom->find('script, style, header, footer, input, button, aside, meta, link');

                foreach ($nodesToRemove as $node)
                {
                    $node->delete();
                    unset($node);
                }

                // Records to store information on the best dom element found thusfar
                $best_element = null;
                $best_element_wc = 0;
                $best_element_wc_ratio = - 1;

                // $html = $dom->outerHtml;
                // Get a list of qualifying nodes we want to evaluate as the top node for content
                $candidateNodes = $this->buildAllNodeList($dom->root);
                $this->log_debug("Candidate node count: " . count($candidateNodes));

                // Find a target best element
                foreach ($candidateNodes as $node)
                {

                    // Calculate the wordcount, whitecount, and wordcount ratio for the text within this element
                    $this_element_wc = str_word_count($node->text(true));
                    $this_element_whitecount = substr_count($node->text(true) , ' ');
                    $this_element_wc_ratio = - 1;

                    // If the wordcount is not zero, then calculation the wc ratio, otherwise set it to -1
                    $this_element_wc_ratio = ($this_element_wc == 0) ? -1 : $this_element_whitecount / $this_element_wc;

                    // Calculate the word count contribution for all children elements
                    $children_wc = 0;
                    $children_num = 0;
                    foreach ($node->getChildren() as $child)
                    {
                        if (in_array($child
                            ->tag
                            ->name() , $this->valid_root_elements))
                        {
                            $children_num++;
                            $children_wc += str_word_count($child->text(true));
                        }
                    }

                    // This is the contribution for this particular element not including the children types above
                    $this_element_wc_contribution = $this_element_wc - $children_wc;

                    // Debug information on this element for development purposes
                    $this->log_debug("Element:\t" . $node
                        ->tag
                        ->name() . "\tTotal WC:\t" . $this_element_wc . "\tTotal White:\t" . $this_element_whitecount . "\tRatio:\t" . number_format($this_element_wc_ratio, 2) . "\tElement WC:\t" . $this_element_wc_contribution . "\tChildren WC:\t" . $children_wc . "\tChild Contributors:\t" . $children_num . "\tBest WC:\t" . $best_element_wc . "\tBest Ratio:\t" . number_format($best_element_wc_ratio, 2) . " " . $node->getAttribute('class'));

                    // Now check to see if this element appears better than any previous one
                    // We do this by first checking to see if this element's WC contribution is greater than the previous
                    if ($this_element_wc_contribution > $best_element_wc)
                    {

                        // If we so we then calculate the improvement ratio from the prior best and avoid division by 0
                        $wc_improvement_ratio = ($best_element_wc == 0) ? 100 : $this_element_wc_contribution / $best_element_wc;

                        // There are three conditions in which this candidate should be chosen
                        //		1. The previous best is zero
                        //		2. The new best is more than 10% greater WC contribution than the prior best
                        //		3. The new element wc ratio is less than the existing best element's ratio
                        if ($best_element_wc == 0 || $wc_improvement_ratio >= 1.10 || $this_element_wc_ratio <= $best_element_wc_ratio)
                        {
                            $best_element_wc = $this_element_wc_contribution;
                            $best_element_wc_ratio = $this_element_wc_ratio;
                            $best_element = $node;
                            $this->log_debug("\t *** New best element ***");
                        }
                    }
                }

                // If we have a candidate element
                if ($best_element)
                {

                    // Now we need to do some sort of peer analysis
                    $best_element = $this->peerAnalysis($best_element);

                    /*
                    // Add space before HTML elements that if removed create concatenation issues (e.g. <p>, <li>)
                    $nodesToEditText = $best_element->find('p, li');
                    
                    foreach($nodesToEditText as $node) {
                    $node->setText(" " . $node->text);
                    }
                    */
                    //
                    // Decode the text
                    //$text = html_entity_decode($best_element->text(true));
                    $text = html_entity_decode($this->getTextForNode($best_element));

                    $markdown = $best_element;
                    // Replace all <h*> and </h*> tags with newlines
                    $markdown = str_replace('<h1>', '#', $text);
                    $markdown = str_replace('<h2>', '##', $text);
                    $markdown = str_replace('<h3>', '###', $text);
                    $markdown = str_replace('<h4>', '####', $text);
                    $markdown = str_replace('<h5>', '#####', $text);
                    $markdown = str_replace('<h6>', '######', $text);

                    $markdown = preg_replace('/<\/h[1-6]>/', "\n", $text);
                    $markdown = str_replace('<p>', '', $text);
                    $markdown = preg_replace('/<\/p>/', "\n", $text);

                    $markdown = strip_tags($text); // Remove all HTML tags
                    $markdown = html_entity_decode($text); // Make sure we have no HTML entities left over
                    $markdown = str_replace("\t", " ", $text); // Replace tabs with spaces
                    $markdown = preg_replace('/ {2,}/', ' ', $text); // Remove multiple spaces
                    $markdown = str_replace("\r", "\n", $text); // convert carriage returns to newlines
                    $markdown = preg_replace("/(\n)+/", "$1", $text); // remove excessive line returns
                    // Set the method so the caller knows which one was used
                    $method = "custom";
                }
                else
                {
                    $method = null;
                }
            }
            else
            {
                $text = $article->getCleanedArticleText();
            }

        }
        catch(\Exception $e)
        {
            $this->log_debug('parseViaGooseOrCustom: Unable to request url ' . $url . " due to " . $e->getMessage());
        }
        if (!isset($markdown))
        {
            $markdown = $text;
        }

        return ['parse_method' => $method, 'title' => $title, 'text' => $text, 'markdown' => $markdown, 'html' => $html];

    }

    private function checkGoogleReferralUrl($url)
    {

        $parse_results = parse_url($url);

        if (isset($parse_results['host']) && isset($parse_results['path']))
        {

            if (strtolower($parse_results['host']) == "www.google.com" && strtolower($parse_results['path'] = "/url"))
            {

                if (isset($parse_results['query']))
                {

                    $items = explode("&", $parse_results['query']);

                    foreach ($items as $item)
                    {
                        $parts = explode("=", $item);
                        if ($parts[0] == 'url')
                        {

                            $url = urldecode($parts[1]);
                        }
                    }
                }
            }
        }
        return $url;
    }

    /**
     * Checks for redirects given a URL. Will return the ultimate final URL if found within
     * 5 redirects. Otherwise, it will return the last url it found and log too many redirects
     */
    private function checkForRedirects($url, $count = 0)
    {
        $this->log_debug("Checking for redirects on " . $url . " count " . $count);

        // First check to see if we've been redirected too many times
        if ($count > 5)
        {
            $this->log_debug("Too many redirects");
            return $url;
        }

        $url = $this->checkGoogleReferralUrl($url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // exclude the body from the request, we only want the header here
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // NOTE: We don't set user-agent here because many of the redirect services will use meta refresh instead of location headers to redirect.
        $a = curl_exec($ch);

        $new_url = $this->findLocationHeader($a);

        if ($new_url != null)
        {
            $this->log_debug("Redirect found to: " . $new_url);

            // Check to see if new redirect has scheme and host
            $parse_results = parse_url($new_url);

            if (!array_key_exists('scheme', $parse_results) && !array_key_exists('host', $parse_results))
            {

                // Use scheme, url, and host from passed in URL
                $old_parse_results = parse_url($url);
                $scheme_host = $old_parse_results['scheme'] . "://" . $old_parse_results['host'];
                if (isset($old_parse_results['port']))
                {
                    $scheme_host .= ":" . $parse_results['port'];
                }

                $full_url = $scheme_host . $new_url;

                $this->log_debug("No scheme and host found for: " . $new_url . " -- Utilizing prior redirect scheme and host: " . $full_url);
                $new_url = $full_url;
            }

            return $this->checkForRedirects($new_url, $count + 1);
        }
        else
        {
            return $url;
        }
    }

    /**
     * Looks for "Location:" or "location:" in the header. Returns null if it can't find it.
     */
    private function findLocationHeader($text)
    {

        $lines = explode("\n", $text);

        foreach ($lines as $line)
        {

            $header_item = explode(":", $line);

            if (mb_strtolower($header_item[0]) == "location")
            {
                $url = trim(mb_substr($line, mb_strpos($line, ":") + 1));
                return $url;
            }
        }

        return null;
    }

    /**
     * Shifts encoding to UTF if needed
     */
    private function shiftEncodingToUTF8($text)
    {

        if ($encoding = mb_detect_encoding($text, mb_detect_order() , true))
        {
            $this->log_debug("shiftEncodingToUTF8 detected encoding of " . $encoding . " -> shifting to UTF-8");
            return iconv($encoding, "UTF-8", $text);
        }
        else
        {
            $this->log_debug("shiftEncodingToUTF8 detected NO encoding -> leaving as is");
            return $text;
        }
    }

    private function peerAnalysis($element)
    {

        $this->log_debug("PEER ANALYSIS ON " . $element
            ->tag
            ->name() . " (" . $element->getAttribute('class') . ")");

        $range = 0.50;

        $element_wc = str_word_count($element->text(true));
        $element_whitecount = substr_count($element->text(true) , ' ');
        $element_wc_ratio = $element_whitecount / $element_wc;

        if ($element->getParent() != null)
        {

            $parent = $element->getParent();
            $this->log_debug("	Parent: " . $parent
                ->tag
                ->name() . " (" . $parent->getAttribute('class') . ")");

            $peers_with_close_wc = 0;

            foreach ($parent->getChildren() as $child)
            {
                $child_wc = str_word_count($child->text(true));
                $child_whitecount = substr_count($child->text(true) , ' ');

                if ($child_wc != 0)
                {
                    $child_wc_ratio = $child_whitecount / $child_wc;

                    $this->log_debug("	  Child: " . $child
                        ->tag
                        ->name() . " (" . $child->getAttribute('class') . ") WC: " . $child_wc . " Ratio: " . number_format($child_wc_ratio, 2));

                    if ($child_wc > ($element_wc * $range) && $child_wc < ($element_wc * (1 + $range)))
                    {
                        $this->log_debug("** good peer found **");
                        $peers_with_close_wc++;
                    }
                }
            }

            if ($peers_with_close_wc > 2)
            {
                $this->log_debug("Returning parent");
                return $parent;
            }
            else
            {
                $this->log_debug("Not enough good peers, returning original element");
                return $element;
            }
        }
        else
        {
            $this->log_debug("Element has no parent - returning original element");
            return $element;
        }
    }

    private function buildAllNodeList($element, $depth = 0)
    {

        $return_array = array();

        // Debug what we are checking
        if ($element->getTag()
            ->name() != "text")
        {

            $this->log_debug("buildAllNodeList: " . str_repeat(' ', $depth * 2) . $element->getTag()
                ->name() . " ( " . $element->getAttribute('class') . " )");

            // Look at each child div element
            if ($element->hasChildren())
            {

                foreach ($element->getChildren() as $child)
                {
                    // Push the children's children
                    $return_array = array_merge($return_array, array_values($this->buildAllNodeList($child, $depth + 1)));

                    // Include the following tags in the counts for children and number of words
                    if (in_array($child
                        ->tag
                        ->name() , $this->valid_root_elements))
                    {
                        array_push($return_array, $child);
                    }
                }
            }
        }
        else
        {
            $this->log_debug("buildAllNodeList: " . str_repeat(' ', $depth * 2) . $element->getTag()
                ->name());
        }
        return $return_array;
    }

    private function log_debug($message)
    {
        if ($this->debug)
        {
            echo $message . "\n";
        }
    }

    /*
     * This function gets the text representation of a node and works recursively to do so.
     * It also trys to format an extra space in HTML elements that create concatenation
     * issues when they are slapped together
    */
    private function getTextForNode($element)
    {

        $text = '';

        $this->log_debug("getTextForNode: " . $element->getTag()
            ->name());

        // Look at each child
        foreach ($element->getChildren() as $child)
        {

            // If its a text node, just give it the nodes text
            if ($child instanceof TextNode)
            {
                $text .= $child->text();
            }
            // Otherwise, if it is an HtmlNode
            elseif ($child instanceof HtmlNode)
            {

                // If this is one of the HTML tags we want to add a space to
                if (in_array($child->getTag()
                    ->name() , $this->space_elements))
                {
                    $text .= " " . $this->getTextForNode($child);
                }
                else
                {
                    $text .= $this->getTextForNode($child);
                }
            }
        }

        // Return our text string
        return $text;
    }

    /**
     * Identifies the language received in the UTF-8 text using the DetectLanguage API key.
     * Returns false if the language could not be identified and the ISO code if it can be
     */
    private function identifyLanguage($text)
    {
        $this->log_debug("identifyLanguage: " . $text);

        if ($this->api_key == null)
        {
            $this->log_debug("identifyLanguage: Cannot detect language. No api key passed in");
            return false;
        }

        try
        {
            // Set the API key for detect language library
            DetectLanguage::setApiKey($this->api_key);

            // Detect the language
            $languageCode = DetectLanguage::simpleDetect($text);

            if ($languageCode == null)
            {
                return false;
            }
            else
            {
                return $languageCode;
            }
        }
        catch(\Exception $e)
        {
            $this->log_debug("identifyLanguage: Error with DetectLanguage routine. Returning false: Message is " . $e->getMessage());
            return false;
        }
    }

    /**
     * Checks the passed in HTML for any hints within the HTML for language. Should
     * return the ISO 639-1 language code if found or false if no language could be determined
     * from the dom model.
     *
     */
    private function checkHTMLForLanguageHint($html_string)
    {

        try
        {
            // Ok then try it a different way
            $dom = new Dom;
            $dom->load($html_string, ['whitespaceTextNode' => false]);

            $htmltag = $dom->find('html');
            $lang = $htmltag->getAttribute('lang');

            // Check for lang in HTML tag
            if ($lang != null)
            {
                $this->log_debug("checkHTMLForLanguageHint: Found language: " . $lang . ", returning " . substr($lang, 0, 2));
                return substr($lang, 0, 2);
            }
            // Otherwise...
            else
            {

                // Check to see if we have a <meta name="content-language" content="ja" /> type tag
                $metatags = $dom->find("meta");

                foreach ($metatags as $tag)
                {
                    $this->log_debug("Checking tag: " . $tag->getAttribute('name'));
                    if ($tag->getAttribute('name') == 'content-language')
                    {
                        return $tag->getAttribute('content');
                    }
                }

                $this->log_debug("checkHTMLForLanguageHint: Found no language");
                return false;
            }
        }
        catch(\Exception $e)
        {
            $this->log_debug("checkHTMLForLanguageHint: Returning false as exception occurred: " . $e->getMessage());
            return false;
        }

    }
}

?>
