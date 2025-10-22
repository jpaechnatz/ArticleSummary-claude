<?php
class ArticleSummaryExtension extends Minz_Extension
{


  protected array $csp_policies = [
    'default-src' => "'self'",
    'connect-src' => "'self' https:",
    'script-src' => "'self' 'unsafe-inline'",
    'style-src' => "'self' 'unsafe-inline'",
  ];

  public function init()
  {
    $this->registerHook('entry_before_display', array($this, 'addSummaryButton'));
    Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
    Minz_View::appendScript($this->getFileUrl('axios.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('marked.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
  }

  public function addSummaryButton($entry)
  {
    // Generate URL for extension action
    // Use the extension controller pattern: ?c=extension&a=action
    $url_summary = _url('extension', 'summarize');

    $entry->_content(
      '<div class="oai-summary-wrap">'
      . '<button data-request="' . htmlspecialchars($url_summary, ENT_QUOTES, 'UTF-8') . '" data-entry-id="' . $entry->id() . '" class="oai-summary-btn"></button>'
      . '<div class="oai-summary-content"></div>'
      . '</div>'
      . $entry->content()
    );
    return $entry;
  }

  public function summarizeAction()
  {
    // Enable error logging FIRST
    error_log("=== ArticleSummary: summarizeAction called ===");
    error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
    error_log("Request URI: " . $_SERVER['REQUEST_URI']);
    error_log("POST data: " . print_r($_POST, true));
    error_log("GET data: " . print_r($_GET, true));

    // Set response header to JSON
    header('Content-Type: application/json');

    // Continue with error logging
    error_log("ArticleSummary: Headers set, proceeding with logic");

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $oai_model = FreshRSS_Context::$user_conf->oai_model;
    $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt;
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider;

    error_log("ArticleSummary: Config - URL: " . ($oai_url ?: 'empty') . ", Provider: " . ($oai_provider ?: 'empty'));

    if (
      $this->isEmpty($oai_url)
      || $this->isEmpty($oai_key)
      || $this->isEmpty($oai_model)
      || $this->isEmpty($oai_prompt)
    ) {
      error_log("ArticleSummary: Missing configuration");
      $response = array(
        'response' => array(
          'data' => 'missing config',
          'error' => 'configuration'
        ),
        'status' => 200
      );
      error_log("ArticleSummary: Returning error response: " . json_encode($response));
      echo json_encode($response);
      exit();
    }

    $entry_id = Minz_Request::param('id');
    error_log("ArticleSummary: Entry ID: " . $entry_id);
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      error_log("ArticleSummary: Entry not found");
      echo json_encode(array('status' => 404));
      exit();
    }

    $content = $entry->content(); // Replace with article content

    // Process $oai_url
    $oai_url = rtrim($oai_url, '/'); // Remove trailing slash
    if (!preg_match('/\/v\d+\/?$/', $oai_url)) {
        $oai_url .= '/v1'; // If there is no version information, add /v1
    }
    // Open AI Input
    $successResponse = array(
      'response' => array(
        'data' => array(
          // Determine whether the URL ends with a version. If it does, no version information is added. If not, /v1 is added by default.
          "oai_url" => $oai_url . '/chat/completions',
          "oai_key" => $oai_key,
          "model" => $oai_model,
          "messages" => [
            [
              "role" => "system",
              "content" => $oai_prompt
            ],
            [
              "role" => "user",
              "content" => "input: \n" . $this->htmlToMarkdown($content),
            ]
          ],
          "max_tokens" => 2048, // You can adjust the length of the summary as needed
          "temperature" => 0.7, // You can adjust the randomness/temperature of the generated text as needed
          "n" => 1 // Generate summary
        ),
        'provider' => 'openai',
        'error' => null
      ),
      'status' => 200
    );

    // Ollama API Input
    if ($oai_provider === "ollama") {
      $successResponse = array(
        'response' => array(
          'data' => array(
            "oai_url" => $oai_url . '/api/generate',
            "oai_key" => $oai_key,
            "model" => $oai_model,
            "system" => $oai_prompt,
            "prompt" =>  $this->htmlToMarkdown($content),
            "stream" => true,
          ),
          'provider' => 'ollama',
          'error' => null
        ),
        'status' => 200
      );
    }
    error_log("ArticleSummary: Returning success response for provider: " . $oai_provider);
    $jsonResponse = json_encode($successResponse);
    error_log("ArticleSummary: JSON output: " . $jsonResponse);
    echo $jsonResponse;
    exit();
  }

  private function isEmpty($item)
  {
    return $item === null || trim($item) === '';
  }

  private function htmlToMarkdown($content)
  {
    // Create DOMDocument object
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Ignore HTML parsing errors
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // Create XPath object
    $xpath = new DOMXPath($dom);

    // Define an anonymous function to process the node
    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      // Process text nodes
      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      // Process element nodes
      if ($node->nodeType === XML_ELEMENT_NODE) {
        switch ($node->nodeName) {
          case 'p':
          case 'div':
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h1':
            $markdown .= "# ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h2':
            $markdown .= "## ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h3':
            $markdown .= "### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h4':
            $markdown .= "#### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h5':
            $markdown .= "##### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h6':
            $markdown .= "###### ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'a':
            // $markdown .= "[";
            // foreach ($node->childNodes as $child) {
            //   $markdown .= $processNode($child);
            // }
            // $markdown .= "](" . $node->getAttribute('href') . ")";
            $markdown .= "`";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "`";
            break;
          case 'img':
            $alt = $node->getAttribute('alt');
            $markdown .= "img: `" . $alt . "`";
            break;
          case 'strong':
          case 'b':
            $markdown .= "**";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "**";
            break;
          case 'em':
          case 'i':
            $markdown .= "*";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "*";
            break;
          case 'ul':
          case 'ol':
            $markdown .= "\n";
            foreach ($node->childNodes as $child) {
              if ($child->nodeName === 'li') {
                $markdown .= str_repeat("  ", $indentLevel) . "- ";
                $markdown .= $processNode($child, $indentLevel + 1);
                $markdown .= "\n";
              }
            }
            $markdown .= "\n";
            break;
          case 'li':
            $markdown .= str_repeat("  ", $indentLevel) . "- ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child, $indentLevel + 1);
            }
            $markdown .= "\n";
            break;
          case 'br':
            $markdown .= "\n";
            break;
          case 'audio':
          case 'video':
            $alt = $node->getAttribute('alt');
            $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
            break;
          default:
            // Tags not considered, only the text inside is kept
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    // Get all nodes
    $nodes = $xpath->query('//body/*');

    // Process all nodes
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    // Remove extra line breaks
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);

    return $markdown;
  }

  public function handleConfigureAction()
  {
    if (Minz_Request::isPost()) {
      $oai_url = trim(Minz_Request::param('oai_url', ''));
      $oai_key = trim(Minz_Request::param('oai_key', ''));
      $oai_model = trim(Minz_Request::param('oai_model', ''));
      $oai_prompt = trim(Minz_Request::param('oai_prompt', ''));
      $oai_provider = Minz_Request::param('oai_provider', 'openai');

      // Validate URL format
      if (!empty($oai_url) && !filter_var($oai_url, FILTER_VALIDATE_URL)) {
        // Invalid URL, don't save
        return;
      }

      // Validate provider is one of the allowed values
      if (!in_array($oai_provider, ['openai', 'ollama'])) {
        $oai_provider = 'openai';
      }

      FreshRSS_Context::$user_conf->oai_url = $oai_url;
      FreshRSS_Context::$user_conf->oai_key = $oai_key;
      FreshRSS_Context::$user_conf->oai_model = $oai_model;
      FreshRSS_Context::$user_conf->oai_prompt = $oai_prompt;
      FreshRSS_Context::$user_conf->oai_provider = $oai_provider;
      FreshRSS_Context::$user_conf->save();
    }
  }
}
