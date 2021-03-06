<?php
use \Httpful\Request as Request;
use \Goutte\Client as Client;

class Gahetna {
    const API_ENDPOINT = "http://www.gahetna.nl/beeldbank-api/opensearch/?q=%s&count=%s&startIndex=%s";
    const IMG_ENDPOINT = "http://afbeeldingen.gahetna.nl/naa/thumb/%s/%s.jpg";
    const DEFAULT_RESOLUTION = "3000x3000";
    const HANDLE_URL_PREFIX = "http://hdl.handle.net/10648/";
    const MAX_FILENAME_LENGTH = 200;

    private function request($q) {
        $url = sprintf(self::API_ENDPOINT, urlencode($q), 100, 1);

        if (defined('DEBUG')) {
            error_log("Calling URL " . $url . "\n");
        }

        $res = Request::get($url)->expectsXml()->send();

        return $res->body;
    }

    private function registerNamespaces($xml) {
        foreach ($this->namespaces as $prefix => $ns) {
            $xml->registerXPathNamespace($prefix, $ns);
        }
    }

    private function getHandleUrl($handle) {
        return str_replace("hdl://", "http://proxy.handle.net/", $handle);
    }

    private function getImageUrl($handle, $resolution = false) {
        if (!$resolution) {
            $resolution = self::DEFAULT_RESOLUTION;
        }

        $id = str_replace("hdl://10648/", "", $handle);
        return sprintf(self::IMG_ENDPOINT, self::DEFAULT_RESOLUTION, $id);
    }

    private function parseItem($item) {
        if (!is_object($item)) {
            return false;
        }

        $data = array(
            "title" => (string) $item->title,
            "link" => (string) $item->link,
            "description" => (string) $item->description,
            "pubDate" => (string) $item->pubDate,
            "guid" => (string) $item->guid
        );

        // Sigh, Xpath...
        $trees = $item->xpath("memorix:MEMORIX/field");
        $photos = array();

        foreach ($trees as $tree) {
            $name = (string) $tree->attributes()->name;

            if (count($tree->value) > 1) {
                foreach ($tree->value as $val) {
                    $photos[$name][] = (string) $val;
                }
            }
        }

        // Okay, now properly construct those images
        foreach ($photos['PhotoName'] as $index => $photo) {
            $handle = $photos['PhotoHandle'][$index];

            $data['photos'][] = array(
                "PhotoName" => $photo,
                "index" => $index,
                "PhotoHandle" => $handle,
                "handle" => $this->getHandleUrl($handle),
                "imageurl" =>$this->getImageUrl($handle)
            );
        }

        return $data;
    }

    private function getUrlParam($param, $url) {
        $regex = "/$param\/([^\/]*)/";
        preg_match($regex, $url, $matches);
        return $matches[1];
    }

    public function getTranscriptForUrl($url) {
        $client = new Client();
        $crawler = $client->request('GET', $url);
        $node = $crawler->filter('#block-na_transcribe-transcription p');

        if ($node && $node->text()) {
            return $node->text();
        } else {
            return "Could not get a transcript...";
        }
    }

    public function query($q) {
        $xml = $this->request($q);

        // We might get more than one item, but for now we
        // do it like this
        $item = $xml->channel->item[0];

        return $this->parseItem($item);
    }

    public function searchForImages($terms) {
        $terms = explode("\n", trim($terms));
        $self = $this;

        return array_map(function($term) use ($self) {
            return array(
                "term" => $term,
                "result" => $self->query($term . " cc-by-sa")
            );
        }, $terms);
    }

    public function getUrlList($url, $affix = false) {
        $eadid = $this->getUrlParam("eadid", $url);
        $inr = $this->getUrlParam("inventarisnr", $url);

        $q = $eadid . "_" . $inr;
        $res = $this->query($q);

        if (!$res) {
            return false;
        }

        $lines = array();

        foreach ($res['photos'] as $photo) {
            $url = $photo['imageurl'];
            $filename = $photo['PhotoName'];

            if ($affix) {
                $filename = $filename . "_$affix";
            }

            // Cap the length of $filename
            $filename = substr($filename, 0, self::MAX_FILENAME_LENGTH);

            $filename = $filename . ".jpg";

            $lines[] = array(
                "url" => $url,
                "filename" => $filename
            );
        }

        return $lines;
    }

    public function getWgetScriptFromUrl($url, $affix = false) {
        $files = $this->getUrlList($url, $affix);

        if (!$files) {
            return "Invalid URL or no files :(";
        }

        $lines = array_map(function($file) {
            extract($file);
            return "wget \"$url\" -O \"$filename\"";
        }, $files);

        return implode("\n", $lines);
    }
}