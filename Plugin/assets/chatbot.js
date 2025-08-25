(function() {
  const root = document.getElementById('fssc-root');
  if (!root || !window.FSSC) return;

  const {
    title,
    datasetUrl,
    nonce,
    clientMaxPerMinute = 12,
    clientCooldownMs = 1200,
    topK = 5 // NEW: number of results to show
  } = window.FSSC;

  // --- Build launcher + panel ---
  root.innerHTML = `
    <button class="fssc-launcher" id="fssc-launcher" aria-label="Open site assistant" aria-expanded="false" aria-controls="fssc-card">
      <svg class="fssc-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4 12a8 8 0 0 1 8-8h0a8 8 0 0 1 8 8v0a8 8 0 0 1-8 8h-1.5a1 1 0 0 0-.7.3l-1.9 1.9c-.63.63-1.7.18-1.7-.7V20A8 8 0 0 1 4 12Z" stroke="currentColor" stroke-width="1.6"/>
      </svg>
    </button>

    <div class="fssc-card" id="fssc-card" role="dialog" aria-label="Site Assistant" aria-modal="false">
      <div class="fssc-header">
        <div class="fssc-title">
          <span>🤖 ${title}</span>
        </div>
        <button class="fssc-close" id="fssc-close" aria-label="Close assistant">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
        </button>
      </div>
      <div class="fssc-body" id="fssc-body">
        <div class="fssc-msg"><div class="fssc-bubble bot">
          Hi! Ask anything about this site. I’ll list the most relevant articles.
        </div></div>
      </div>
      <div class="fssc-input">
        <input id="fssc-input" type="text" placeholder="Type your question and press Enter…" />
        <button id="fssc-send">Search</button>
      </div>
      <div class="fssc-footer">Fast Site Search Chatbot</div>
    </div>
  `;

  // Elements
  const cardEl = document.getElementById('fssc-card');
  const launcherEl = document.getElementById('fssc-launcher');
  const closeEl = document.getElementById('fssc-close');
  const bodyEl = document.getElementById('fssc-body');
  const inputEl = document.getElementById('fssc-input');
  const sendBtn = document.getElementById('fssc-send');

  // State
  let mini = null;
  let lastQueryAt = 0;
  let open = false;

  // --- Open/Close helpers ---
  function openPanel() {
    if (open) return;
    open = true;
    cardEl.classList.add('open');
    launcherEl.setAttribute('aria-expanded', 'true');
    setTimeout(() => inputEl && inputEl.focus(), 0);
  }
  function closePanel() {
    if (!open) return;
    open = false;
    cardEl.classList.remove('open');
    launcherEl.setAttribute('aria-expanded', 'false');
    launcherEl.focus();
  }

  launcherEl.addEventListener('click', () => openPanel());
  closeEl.addEventListener('click', () => closePanel());
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && open) closePanel();
  });

  // --- UI helpers ---
  function addMsg(text, who='bot', html=false){
    const wrap = document.createElement('div');
    wrap.className = `fssc-msg ${who}`;
    const b = document.createElement('div');
    b.className = `fssc-bubble ${who}`;
    if (html) b.innerHTML = text; else b.textContent = text;
    wrap.appendChild(b);
    bodyEl.appendChild(wrap);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  function sanitize(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

  // --- Throttle (client) ---
  function canQueryNow(){
    const now = Date.now();
    if (now - lastQueryAt < clientCooldownMs) return { ok:false, reason:'Please wait a moment before sending another query.' };
    const key = 'fssc_qtimes';
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(key) || '[]'); } catch (_) {}
    arr = arr.filter(ts => (now - ts) < 60_000);
    if (arr.length >= clientMaxPerMinute) return { ok:false, reason:`Limit reached (${clientMaxPerMinute}/min). Try again in a minute.` };
    arr.push(now);
    try { localStorage.setItem(key, JSON.stringify(arr)); } catch (_) {}
    lastQueryAt = now;
    return { ok:true };
  }

  // --- Dataset load ---
  async function loadDataset(){
    try{
      const res = await fetch(datasetUrl,{credentials:'same-origin', headers:{'X-WP-Nonce':nonce}});
      if(!res.ok) throw new Error('Dataset fetch failed');
      const data = await res.json();
      const docs = data.docs || [];

      mini = new MiniSearch({
        fields: ['title','text'],
        storeFields: ['id','title','url','text','date','type'],
        searchOptions: { boost: { title: 2 }, prefix: true, fuzzy: 1 }
      });
      mini.addAll(docs);

      // Nice: auto-open on first visit? Keep closed for minimal impact.
      addMsg(`Index ready: ${docs.length} documents loaded.`);
    }catch(e){
      console.error(e);
      addMsg('Failed to load dataset. Please contact the site administrator.');
    }
  }

  // --- Results (titles only as bullet list) ---
  function resultsList(results){
    const items = results.map(r => `<li><a href="${sanitize(r.url)}" target="_blank" rel="noopener">${sanitize(r.title)}</a></li>`).join('');
    return `<ul class="fssc-reslist">${items}</ul>`;
  }

  async function handleQuery(raw){
    const q=(raw||'').trim();
    if(!q||!mini) return;

    const gate = canQueryNow();
    if (!gate.ok) { addMsg(gate.reason); return; }

    addMsg(q,'user');

    const results = mini.search(q,{combineWith:'AND'}).slice(0, Math.max(1, Math.min(10, topK)));
    if(!results.length){ addMsg('No matches found. Try different wording or be more specific.'); return; }

    let html = `<div>Top matches:</div>${resultsList(results)}`;
    addMsg(html,'bot',true);
  }

  function onSend(){
    const q=(inputEl.value||'').trim();
    inputEl.value='';
    if(!open) openPanel();
    if(q) handleQuery(q);
  }

  sendBtn.addEventListener('click', onSend);
  inputEl.addEventListener('keydown', e => { if(e.key==='Enter') onSend(); });

  loadDataset();
})();
