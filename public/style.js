(function(){
  const css = `
  :root{
    --bg:#f7f8fb; --panel:#fff; --text:#0f172a; --muted:#64748b;
    --primary:#1769ff; --primary-ghost:rgba(23,105,255,.12);
    --ok:#16a34a; --warn:#f59e0b; --border:#e6e8ef; --shadow:0 6px 22px rgba(15,23,42,.06);
    --radius:16px; --sidebar:#0b1225; --sidebar-text:#e5edff; --sidebar-muted:#8ea1c9;
  }
  *{box-sizing:border-box} html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 ui-sans-serif,system-ui,Segoe UI,Roboto,Arial}
  .hidden{display:none!important}
  .small{font-size:12px}

  /* App shell */
  .sidebar{
    position:fixed; inset:0 auto 0 0; width:240px; background:var(--sidebar); color:var(--sidebar-text);
    display:flex; flex-direction:column; padding:16px 12px; gap:16px;
  }
  .brand{display:flex; align-items:center; gap:10px; padding:8px 10px}
  .logo{width:34px;height:34px; border-radius:10px; display:grid; place-items:center; background:#132042; font-size:18px}
  .brand-text{font-weight:700; letter-spacing:.3px}
  .nav{display:flex; flex-direction:column; gap:6px}
  .nav-bottom{margin-top:auto}
  .nav-item{
    display:flex; align-items:center; gap:10px; width:100%;
    padding:10px 12px; border:0; border-radius:10px; color:var(--sidebar-text); background:transparent; cursor:pointer;
  }
  .nav-item .nav-ico{width:18px; text-align:center}
  .nav-item:hover{background:#121a33}
  .nav-item.active{background:#19244a}

  .app-header{
    position:fixed; left:240px; right:0; top:0; height:64px; display:flex; align-items:center;
    padding:0 20px; background:var(--panel); border-bottom:1px solid var(--border); z-index:10;
  }
  .crumbs{font-weight:600}
  .userbar{margin-left:auto; display:flex; align-items:center; gap:10px}
  .avatar{width:36px;height:36px;border-radius:50%}
  .muted{color:var(--muted)}
  .app-main{padding:90px 28px 28px 268px}

  /* Cards + grids */
  .card{background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px}
  .grid{display:grid; gap:16px}
  .cards{grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}
  .course-card,.room-card{
    background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px;
    display:flex; flex-direction:column; gap:10px; transition:transform .1s ease, box-shadow .2s ease;
  }
  .course-card:hover,.room-card:hover{transform:translateY(-2px); box-shadow:0 10px 28px rgba(15,23,42,.08)}
  .course-title,.room-title{margin:0; font-size:18px}
  .badge{display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; background:var(--primary-ghost); color:var(--primary); font-weight:600; font-size:12px}

  .btn{cursor:pointer; border:0; border-radius:12px; padding:10px 14px; font-weight:700}
  .btn-primary{background:var(--primary); color:#fff}
  .btn-primary:hover{filter:brightness(.95)}
  .btn-ghost{background:var(--primary-ghost); color:var(--primary)}
  .btn-link{background:transparent; color:var(--primary); padding:8px 10px}
  .spacer{flex:1}

  /* Centered login card */
  .centerbox{max-width:460px; margin:8vh auto}
/* --- Progress Section --- */
.progress-section {
    margin-top: 40px;
}

.progress-section h2 {
    font-size: 1.75rem;
    margin-bottom: 20px;
}

.progress-title {
    margin: 0 0 10px;
    font-weight: 600;
    font-size: 1.05rem;
}

.progress-row {
    display: grid;
    grid-auto-flow: column; /* This makes the items line up horizontally */
    grid-auto-columns: minmax(150px, 1fr); /* Each card will be at least 150px wide */
    gap: 16px;
    padding-bottom: 16px;
    overflow-x: auto; /* This makes the row scrollable if it's too wide */
    -webkit-overflow-scrolling: touch; /* Smooth scrolling on mobile */
}

/* Progress Cell (Your card style) */
.progress-cell {
    background: var(--card-background);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 12px 14px;
    min-height: 80px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.04);
}

.progress-cell .detail-name {
    font-size: 0.95rem;
    font-weight: 500;
    line-height: 1.3;
}

.progress-cell .status {
    margin-top: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 6px;
    padding: 4px 8px;
    width: fit-content;
}

/* Status colors (from your code) */
.status-none     { background: #f6f7f8; color: #666; }
.status-pending  { background: #f7e3a1; color: #6a5400; }
.status-completed{ background: #dff3e3; color: #1e6a3b; }
.status-review   { background: #f7d3d6; color: #7b1d25; }

  /* Queues inside each room */
  .queues{display:flex; flex-direction:column; gap:8px}
  .queue-row{display:flex; flex-direction:column; gap:12px; padding:12px 14px; border:1px dashed var(--border); border-radius:12px}
  .queue-header{display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap}
  .queue-header-text{flex:1; min-width:200px; display:flex; flex-direction:column; gap:4px}
  .queue-meta{display:flex; flex-direction:column; align-items:flex-end; gap:2px; min-width:140px}
  .queue-count{font-weight:600; font-size:14px}
  .queue-eta{font-size:12px; color:var(--muted)}
  .queue-actions{display:flex; gap:8px; align-items:center}
  .q-name{font-weight:700}
  .q-desc{color:var(--muted); font-size:13px}
  .queue-occupants{display:flex; flex-direction:column; gap:6px}
  .queue-occupants.empty{color:var(--muted); font-size:13px}
  .occupant-label{font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--muted)}
  .occupant-pills{display:flex; flex-wrap:wrap; gap:6px}
  .pill{padding:4px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:12px}
  .pill.you{background:#dcfce7; color:#166534}

  /* Skeletons */
  .sk{position:relative; overflow:hidden; background:#eef0f5; border-radius:12px; height:120px}
  .sk::after{content:""; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(255,255,255,.6),transparent);
    transform:translateX(-100%); animation:sh 1.2s infinite}
  @keyframes sh{to{transform:translateX(100%)}}

  @media(max-width:920px){
    .sidebar{width:76px}
    .nav-item span:last-child{display:none}
    .brand-text{display:none}
    .app-header{left:76px}
    .app-main{padding-left:100px}
  }
  @media(max-width:600px){
    .queue-meta{align-items:flex-start; min-width:0}
  }
  `;
  const el=document.createElement('style');
  el.textContent=css;
  document.head.appendChild(el);
})();