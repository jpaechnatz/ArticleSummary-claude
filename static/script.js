if (document.readyState && document.readyState !== 'loading') {
  configureSummarizeButtons();
} else {
  document.addEventListener('DOMContentLoaded', configureSummarizeButtons, false);
}

function configureSummarizeButtons() {
  document.getElementById('global').addEventListener('click', function (e) {
    for (var target = e.target; target && target != this; target = target.parentNode) {
      
      if (target.matches('.flux_header')) {
        target.nextElementSibling.querySelector('.oai-summary-btn').innerHTML = 'Summarize'
      }

      if (target.matches('.oai-summary-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          summarizeButtonClick(target);
        }
        break;
      }
    }
  }, false);
}

function setOaiState(container, statusType, statusMsg, summaryText) {
  const button = container.querySelector('.oai-summary-btn');
  const content = container.querySelector('.oai-summary-content');
  // Set different states based on statusType
  if (statusType === 1) {
    container.classList.add('oai-loading');
    container.classList.remove('oai-error');
    content.innerHTML = statusMsg;
    button.disabled = true;
  } else if (statusType === 2) {
    container.classList.remove('oai-loading');
    container.classList.add('oai-error');
    content.innerHTML = statusMsg;
    button.disabled = false;
  } else {
    container.classList.remove('oai-loading');
    container.classList.remove('oai-error');
    if (statusMsg === 'finish'){
      button.disabled = false;
    }
  }

  if (summaryText) {
    content.innerHTML = summaryText;
  }
}

async function summarizeButtonClick(target) {
  var container = target.parentNode;
  if (container.classList.contains('oai-loading')) {
    return;
  }

  setOaiState(container, 1, 'Loading...', null);

  // This is the address where PHP gets the parameters
  var url = target.dataset.request;
  var entryId = target.dataset.entryId;

  // Convert to URLSearchParams for form-encoded data
  var data = new URLSearchParams();
  data.append('ajax', 'true');
  data.append('_csrf', context.csrf);
  data.append('id', entryId);

  try {
    const response = await axios.post(url, data, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const xresp = response.data;

    if (response.status !== 200 || !xresp.response || !xresp.response.data) {
      console.error('Invalid response structure:', xresp);
      throw new Error('Request Failed: Invalid response structure - ' + JSON.stringify(xresp));
    }

    if (xresp.response.error) {
      console.error('Configuration error:', xresp.response.data);
      setOaiState(container, 2, xresp.response.data, null);
    } else {
      const summary = xresp.response.data.summary || '';
      if (!summary) {
        throw new Error('Request Failed: Missing summary content');
      }
      setOaiState(container, 0, 'finish', marked.parse(summary));
    }
  } catch (error) {
    console.error('Full error details:', error);
    console.error('Error message:', error.message);
    console.error('Error response:', error.response);
    let errorMsg = 'Request Failed';
    if (error.response) {
      const statusLabel = error.response.statusText || error.response.status;
      if (statusLabel) {
        errorMsg += ': ' + statusLabel;
      }
      const serverData = error.response.data;
      if (serverData && serverData.response && serverData.response.data) {
        errorMsg = serverData.response.data;
      }
    } else if (error.message) {
      errorMsg += ': ' + error.message;
    }
    setOaiState(container, 2, errorMsg, null);
  }
}

