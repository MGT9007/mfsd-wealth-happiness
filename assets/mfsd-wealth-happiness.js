(function () {
  console.log('MFSD Wealth & Happiness Chat loaded');
  console.log('MFSD_WH_CFG', window.MFSD_WH_CFG);
  
  const cfg = window.MFSD_WH_CFG || {};
  const root = document.getElementById("mfsd-wh-root");
  if (!root) {
    console.error('Root element not found');
    return;
  }

  let messages = [];
  let userContext = null;
  let isTyping = false;

  const INITIAL_AI_MESSAGE = "Hi! So you've seen all those images of successful, wealthy and reportedly happy people in our matching pairs game... so tell me, do you think that success and wealth are integral to happiness?";

  // Helper to create elements
  const el = (tag, className, text) => {
    const elem = document.createElement(tag);
    if (className) elem.className = className;
    if (text !== undefined) elem.textContent = text;
    return elem;
  };

  // Format time as HH:MM
  const formatTime = () => {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
  };

  // Initialize the app
  async function init() {
    showLoading();
    
    try {
      // Load user context
      await loadUserContext();
      
      // Load existing conversation
      const savedMessages = await loadConversation();
      
      if (savedMessages && savedMessages.length > 0) {
        messages = savedMessages;
        renderChat();
      } else {
        // New conversation - show intro
        renderIntro();
      }
    } catch (err) {
      console.error('Init error:', err);
      showError('Failed to load. Please refresh the page.');
    }
  }

  function showLoading() {
    const container = el('div', 'wh-loading-screen');
    const spinner = el('div', 'wh-spinner');
    const text = el('div', 'wh-loading-text', 'Loading...');
    
    container.appendChild(spinner);
    container.appendChild(text);
    root.replaceChildren(container);
  }

  function showError(message) {
    const container = el('div', 'wh-intro-screen');
    const card = el('div', 'wh-intro-card');
    card.appendChild(el('div', 'wh-intro-icon', '⚠️'));
    card.appendChild(el('h2', 'wh-intro-title', 'Oops!'));
    card.appendChild(el('p', 'wh-intro-text', message));
    
    const retryBtn = el('button', 'wh-start-btn', 'Try Again');
    retryBtn.onclick = () => init();
    card.appendChild(retryBtn);
    
    container.appendChild(card);
    root.replaceChildren(container);
  }

  async function loadUserContext() {
    try {
      const res = await fetch(cfg.restUrlContext, {
        method: 'GET',
        headers: {
          'X-WP-Nonce': cfg.nonce || '',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      if (!res.ok) throw new Error('Failed to load context');
      
      const data = await res.json();
      if (data.ok) {
        userContext = data.context;
        console.log('User context loaded:', userContext);
      }
    } catch (err) {
      console.error('Error loading context:', err);
      userContext = {};
    }
  }

  async function loadConversation() {
    try {
      const res = await fetch(cfg.restUrlLoad, {
        method: 'GET',
        headers: {
          'X-WP-Nonce': cfg.nonce || '',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      if (!res.ok) throw new Error('Failed to load conversation');
      
      const data = await res.json();
      if (data.ok && data.messages) {
        return data.messages;
      }
    } catch (err) {
      console.error('Error loading conversation:', err);
    }
    
    return [];
  }

  async function saveConversation() {
    try {
      await fetch(cfg.restUrlSave, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({ messages: messages })
      });
    } catch (err) {
      console.error('Error saving conversation:', err);
    }
  }

  function renderIntro() {
    const container = el('div', 'wh-intro-screen');
    const card = el('div', 'wh-intro-card');
    
    card.appendChild(el('div', 'wh-intro-icon', '💭'));
    card.appendChild(el('h2', 'wh-intro-title', 'Success, Wealth & Happiness Part 2'));
    
    const introText = el('p', 'wh-intro-text');
    introText.innerHTML = 'Welcome to a thought-provoking conversation about success, wealth, and what really makes people happy.<br><br>Ready to challenge your thinking?';
    card.appendChild(introText);

    // Show user context if available
    if (userContext) {
      const contextDiv = el('div');
      contextDiv.style.marginBottom = '20px';
      
      if (userContext.dream_job && userContext.dream_job.job_title) {
        const badge = el('span', 'wh-context-badge', '🎯 Dream job: ' + userContext.dream_job.job_title);
        contextDiv.appendChild(badge);
      }
      
      if (userContext.mbti_type) {
        const badge = el('span', 'wh-context-badge', '🧠 MBTI: ' + userContext.mbti_type);
        contextDiv.appendChild(badge);
      }
      
      if (contextDiv.children.length > 0) {
        card.appendChild(contextDiv);
      }
    }

    const questionBox = el('div', 'wh-intro-question');
    const questionText = el('p');
    questionText.textContent = INITIAL_AI_MESSAGE;
    questionBox.appendChild(questionText);
    card.appendChild(questionBox);
    
    const startBtn = el('button', 'wh-start-btn', "Let's Talk! 💬");
    startBtn.onclick = startConversation;
    card.appendChild(startBtn);
    
    container.appendChild(card);
    root.replaceChildren(container);
  }

  async function startConversation() {
    // Add the initial AI message
    const initialMessage = {
      sender: 'ai',
      text: INITIAL_AI_MESSAGE,
      timestamp: new Date().toISOString()
    };
    
    messages = [initialMessage];
    await saveConversation();
    renderChat();
  }

  function renderChat() {
    const container = el('div', 'wh-container');
    
    // Header
    const header = el('div', 'wh-header');
    const avatar = el('div', 'wh-header-avatar', '🤖');
    const headerInfo = el('div', 'wh-header-info');
    const title = el('h1', 'wh-header-title', 'Debate Bot');
    const subtitle = el('p', 'wh-header-subtitle', 'online');
    
    headerInfo.appendChild(title);
    headerInfo.appendChild(subtitle);
    header.appendChild(avatar);
    header.appendChild(headerInfo);
    
    // Chat area
    const chatArea = el('div', 'wh-chat-area');
    chatArea.id = 'wh-chat-messages';
    
    // Render all messages
    messages.forEach((msg, index) => {
      const messageElem = createMessageElement(msg, index === 0);
      chatArea.appendChild(messageElem);
    });
    
    // Input area
    const inputArea = el('div', 'wh-input-area');
    const input = el('textarea', 'wh-input');
    input.id = 'wh-user-input';
    input.placeholder = 'Type a message...';
    input.rows = 1;
    
    // Auto-resize textarea
    input.addEventListener('input', function() {
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });
    
    // Send on Enter (but allow Shift+Enter for new line)
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
    
    const sendBtn = el('button', 'wh-send-btn');
    sendBtn.id = 'wh-send-btn';
    sendBtn.innerHTML = '➤';
    sendBtn.onclick = sendMessage;
    
    inputArea.appendChild(input);
    inputArea.appendChild(sendBtn);
    
    container.appendChild(header);
    container.appendChild(chatArea);
    container.appendChild(inputArea);
    
    root.replaceChildren(container);
    
    // Scroll to bottom
    setTimeout(() => scrollToBottom(), 100);
    
    // Focus input
    input.focus();
  }

  function createMessageElement(msg, isFirst = false) {
    const messageDiv = el('div', `wh-message ${msg.sender}`);
    if (isFirst && msg.sender === 'ai') {
      messageDiv.classList.add('first-message');
    }
    
    const bubble = el('div', 'wh-message-bubble');
    const text = el('p', 'wh-message-text', msg.text);
    
    const time = el('div', 'wh-message-time');
    const timestamp = msg.timestamp ? new Date(msg.timestamp) : new Date();
    const timeStr = `${String(timestamp.getHours()).padStart(2, '0')}:${String(timestamp.getMinutes()).padStart(2, '0')}`;
    time.textContent = timeStr;
    
    if (msg.sender === 'user') {
      const checkmark = el('span', 'wh-checkmark', '✓✓');
      time.appendChild(checkmark);
    }
    
    bubble.appendChild(text);
    bubble.appendChild(time);
    messageDiv.appendChild(bubble);
    
    return messageDiv;
  }

  function addTypingIndicator() {
    const chatArea = document.getElementById('wh-chat-messages');
    if (!chatArea) return;
    
    const typingDiv = el('div', 'wh-message ai');
    typingDiv.id = 'wh-typing-indicator';
    
    const bubble = el('div', 'wh-message-bubble');
    const typingAnim = el('div', 'wh-typing');
    typingAnim.appendChild(el('div', 'wh-typing-dot'));
    typingAnim.appendChild(el('div', 'wh-typing-dot'));
    typingAnim.appendChild(el('div', 'wh-typing-dot'));
    
    bubble.appendChild(typingAnim);
    typingDiv.appendChild(bubble);
    chatArea.appendChild(typingDiv);
    
    scrollToBottom();
  }

  function removeTypingIndicator() {
    const indicator = document.getElementById('wh-typing-indicator');
    if (indicator) {
      indicator.remove();
    }
  }

  function scrollToBottom() {
    const chatArea = document.getElementById('wh-chat-messages');
    if (chatArea) {
      chatArea.scrollTop = chatArea.scrollHeight;
    }
  }

  async function sendMessage() {
    const input = document.getElementById('wh-user-input');
    const sendBtn = document.getElementById('wh-send-btn');
    
    if (!input || !sendBtn) return;
    
    const message = input.value.trim();
    if (!message || isTyping) return;
    
    // Disable input
    isTyping = true;
    input.disabled = true;
    sendBtn.disabled = true;
    
    // Add user message
    const userMessage = {
      sender: 'user',
      text: message,
      timestamp: new Date().toISOString()
    };
    
    messages.push(userMessage);
    
    const chatArea = document.getElementById('wh-chat-messages');
    if (chatArea) {
      const msgElem = createMessageElement(userMessage);
      chatArea.appendChild(msgElem);
      scrollToBottom();
    }
    
    // Clear input
    input.value = '';
    input.style.height = 'auto';
    
    // Save conversation
    await saveConversation();
    
    // Show typing indicator
    addTypingIndicator();
    
    try {
      // Get AI response
      const response = await fetch(cfg.restUrlChat, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          message: message,
          conversation: messages,
          context: userContext
        })
      });
      
      if (!response.ok) {
        throw new Error('AI request failed');
      }
      
      const data = await response.json();
      
      if (!data.ok || !data.response) {
        throw new Error('Invalid AI response');
      }
      
      // Remove typing indicator
      removeTypingIndicator();
      
      // Add AI response
      const aiMessage = {
        sender: 'ai',
        text: data.response,
        timestamp: new Date().toISOString()
      };
      
      messages.push(aiMessage);
      
      if (chatArea) {
        const aiMsgElem = createMessageElement(aiMessage);
        chatArea.appendChild(aiMsgElem);
        scrollToBottom();
      }
      
      // Save updated conversation
      await saveConversation();
      
    } catch (err) {
      console.error('Error sending message:', err);
      
      removeTypingIndicator();
      
      // Show error message
      const errorMessage = {
        sender: 'ai',
        text: "Oops! Something went wrong. Could you try saying that again?",
        timestamp: new Date().toISOString()
      };
      
      messages.push(errorMessage);
      
      if (chatArea) {
        const errorElem = createMessageElement(errorMessage);
        chatArea.appendChild(errorElem);
        scrollToBottom();
      }
    } finally {
      // Re-enable input
      isTyping = false;
      input.disabled = false;
      sendBtn.disabled = false;
      input.focus();
    }
  }

  // Initialize the app
  init();
})();