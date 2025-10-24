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
    $this->registerController('ArticleSummary');
    Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
    Minz_View::appendScript($this->getFileUrl('axios.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('marked.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
  }

  public function addSummaryButton($entry)
  {
    // Generate URL for extension action
    // Use the extension controller pattern: ?c=ArticleSummary&a=summarize
    // This routes to Controllers/ArticleSummaryController.php
    $url_summary = _url('ArticleSummary', 'summarize', 'ajax', '1');

    $entry->_content(
      '<div class="oai-summary-wrap">'
      . '<button data-request="' . htmlspecialchars($url_summary, ENT_QUOTES, 'UTF-8') . '" data-entry-id="' . $entry->id() . '" class="oai-summary-btn"></button>'
      . '<div class="oai-summary-content"></div>'
      . '</div>'
      . $entry->content()
    );
    return $entry;
  }

  public function handleConfigureAction()
  {
    parent::handleConfigureAction();

    if (!Minz_Request::isPost()) {
      return;
    }

    $currentConfig = $this->getUserConfiguration();

    $oai_url = trim((string)Minz_Request::param('oai_url', ''));
    $oai_key_param = Minz_Request::param('oai_key', null);
    $oai_model = trim((string)Minz_Request::param('oai_model', ''));
    $oai_prompt = trim((string)Minz_Request::param('oai_prompt', ''));
    $oai_provider = strtolower(trim((string)Minz_Request::param('oai_provider', 'openai')));
    $clear_oai_key = Minz_Request::paramBoolean('clear_oai_key');
    $oai_temperature_param = trim((string)Minz_Request::param('oai_temperature', ''));
    $oai_max_tokens_param = trim((string)Minz_Request::param('oai_max_tokens', ''));

    // Validate URL format
    if (!empty($oai_url) && !filter_var($oai_url, FILTER_VALIDATE_URL)) {
      // Invalid URL, don't save
      return;
    }

    // Validate provider is one of the allowed values
    if (!in_array($oai_provider, ['openai', 'mistral', 'ollama'], true)) {
      $oai_provider = 'openai';
    }

    $config = $currentConfig;
    $config['oai_url'] = $oai_url;
    if ($clear_oai_key) {
      $config['oai_key'] = '';
    } elseif ($oai_key_param !== null && trim((string)$oai_key_param) !== '') {
      $config['oai_key'] = trim((string)$oai_key_param);
    }
    $config['oai_model'] = $oai_model;
    $config['oai_prompt'] = $oai_prompt;
    $config['oai_provider'] = $oai_provider;

    if ($oai_temperature_param === '' || $oai_temperature_param === null) {
      $config['oai_temperature'] = null;
    } else {
      $temperature = (float)$oai_temperature_param;
      if ($temperature < 0) {
        $temperature = 0.0;
      } elseif ($temperature > 2) {
        $temperature = 2.0;
      }
      $config['oai_temperature'] = $temperature;
    }

    if ($oai_max_tokens_param === '' || $oai_max_tokens_param === null) {
      $config['oai_max_tokens'] = null;
    } else {
      $maxTokens = (int)$oai_max_tokens_param;
      if ($maxTokens < 0) {
        $maxTokens = 0;
      }
      $config['oai_max_tokens'] = $maxTokens;
    }

    $this->setUserConfiguration($config);
  }
}
