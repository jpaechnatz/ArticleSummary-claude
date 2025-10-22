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
    // Use the extension controller pattern: ?c=articlesummary&a=summarize
    // This routes to Controllers/articlesummaryController.php
    $url_summary = _url('articlesummary', 'summarize');

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
