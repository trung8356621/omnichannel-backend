import React, { useState, useRef, useEffect } from 'react';
import {
  buildFlowTheme,
  getDefaultNodeHeight,
  getInputPortCenterX,
  getInputPortCenterY,
  getOutputPortCenterX,
  getOutputPortTop,
  getPromptOutputPorts,
  nodeBorderClass,
} from './flowTheme';

// Bộ Icon
const Icons = {
  Article: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10l6 6v10a2 2 0 01-2 2z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 4v5h5M10 12h4m-4 4h4" /></svg>,
  Prompt: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>,
  Filter: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>,
  Play: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>,
  Lightning: () => <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.381z" clipRule="evenodd" /></svg>,
  Trash: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>,
};

const defaultMockPrompts = [
  { id: 'p1', name: 'Outline & Entity JSON', tasks: [{id: 'task_1', name: 'Outline H1,H2'}, {id: 'task_2', name: 'JSON data'}] },
  { id: 'p2', name: 'Write detailed body', tasks: [{id: 'task_1', name: 'Write content'}] },
  { id: 'p3', name: 'Analyze & optimize old article', tasks: [{id: 'task_1', name: 'SEO scoring'}, {id: 'task_2', name: 'Rewrite Title/Meta'}, {id: 'task_3', name: 'Suggest internal links'}] }
];

const mockPrompts =
  typeof window !== 'undefined' && Array.isArray(window.__SEO_PROMPTS__) && window.__SEO_PROMPTS__.length > 0
    ? window.__SEO_PROMPTS__
    : defaultMockPrompts;

const mockPostTypes = ['post', 'page', 'product', 'news'];
const mockTaxonomies = ['category', 'post_tag', 'product_cat', 'brand'];
const mockActions = [
  { id: 'create', label: 'Create' },
  { id: 'update', label: 'Update' },
  { id: 'add_comment_review', label: 'Add comment/review' },
];

const commentReviewFilters = [
  { id: 'with', label: 'Has comment/review' },
  { id: 'without', label: 'No comment/review yet' },
];

const commentReviewLabels = Object.fromEntries(commentReviewFilters.map((o) => [o.id, o.label]));

const actionLabels = Object.fromEntries(mockActions.map((a) => [a.id, a.label]));

function getPromptConfig(promptId) {
  if (promptId == null || promptId === '') {
    return null;
  }

  return mockPrompts.find((p) => String(p.id) === String(promptId)) ?? null;
}

function defaultPromptNodeData(promptId) {
  const config = getPromptConfig(promptId) ?? mockPrompts[0];

  return {
    promptId: config?.id ?? 'p1',
    aiModel: config?.defaultModel ?? '',
  };
}

function normalizeArticleNodeData(data = {}) {
  const next = { ...data };
  if (!Array.isArray(next.actions)) {
    next.actions = next.action ? [next.action] : [];
  }
  delete next.action;
  if (!Array.isArray(next.postTypes)) next.postTypes = [];
  if (!Array.isArray(next.taxonomies)) next.taxonomies = [];
  if (!Array.isArray(next.commentReview)) next.commentReview = [];
  return next;
}

function migrateLegacyFlowNode(node) {
  if (node.type !== 'end') {
    return node;
  }

  return {
    ...node,
    type: 'action',
    title: node.title === 'Save / End' ? 'Action' : node.title,
    data: {
      actionType: node.data?.actionType ?? 'create_article',
      isTrigger: Boolean(node.data?.isTrigger),
    },
  };
}

function normalizeNodes(nodes) {
  return (nodes ?? []).map((node) => {
    const migrated = migrateLegacyFlowNode(node);
    let next = migrated.type === 'article'
      ? { ...migrated, data: normalizeArticleNodeData(migrated.data) }
      : migrated;

    if (next.type === 'prompt') {
      const config = getPromptConfig(next.data?.promptId);
      next = {
        ...next,
        data: {
          ...next.data,
          aiModel: next.data?.aiModel || config?.defaultModel || '',
        },
      };
    }

    return next;
  });
}

function formatSelection(values, labels = {}) {
  if (!values?.length) return 'All';
  return values.map((v) => labels[v] ?? v).join(', ');
}

function useDarkMode() {
  const [isDark, setIsDark] = useState(
    typeof document !== 'undefined' ? document.documentElement.classList.contains('dark') : true,
  );

  useEffect(() => {
    const observer = new MutationObserver(() => {
      setIsDark(document.documentElement.classList.contains('dark'));
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);

  return isDark;
}

export default function ArticleFlowBuilder({ initialData, onSave, taskName, setTaskName }) {
  const isDark = useDarkMode();
  const t = buildFlowTheme(isDark);

  const [nodes, setNodes] = useState(() =>
    normalizeNodes(
      initialData?.nodes ?? [
        {
          id: 'n1',
          type: 'article',
          title: 'Article (Input)',
          x: 50,
          y: 150,
          data: { postTypes: [], taxonomies: [], actions: [], commentReview: [] },
        },
      ],
    ),
  );
  const [edges, setEdges] = useState(initialData?.edges || []);
  const [selectedNodeId, setSelectedNodeId] = useState('n1');
  
  const [isDragging, setIsDragging] = useState(false);
  const [draggedNodeId, setDraggedNodeId] = useState(null);
  const [dragOffset, setDragOffset] = useState({ x: 0, y: 0 });
  const [connecting, setConnecting] = useState(null);

  const canvasRef = useRef(null);

  const addNode = (type) => {
    const id = `node_${Date.now()}`;
    let title = '', data = {};
    if (type === 'article') {
      title = 'Article';
      data = { postTypes: [], taxonomies: [], actions: [], commentReview: [] };
    }
    else if (type === 'prompt') {
      title = 'Prompt block';
      data = defaultPromptNodeData(mockPrompts[0]?.id ?? 'p1');
    }
    else if (type === 'filter') { title = 'Filter / Process'; data = { filterType: 'custom', rule: '' }; }
    else if (type === 'action') {
      title = 'Action';
      data = { actionType: 'create_article', isTrigger: false };
    }
    setNodes([...nodes, { id, type, title, x: 100, y: 100, data }]);
    setSelectedNodeId(id);
  };

  const deleteNode = (id) => {
    setNodes(nodes.filter(n => n.id !== id));
    setEdges(edges.filter(e => e.sourceNode !== id && e.targetNode !== id));
    if (selectedNodeId === id) setSelectedNodeId(null);
  };

  const updateNodeData = (nodeId, key, value) => {
    setNodes(nodes.map(node => node.id === nodeId ? { ...node, data: { ...node.data, [key]: value } } : node));
  };

  const updateNodeDataFields = (nodeId, patch) => {
    setNodes(nodes.map((node) => (node.id === nodeId ? { ...node, data: { ...node.data, ...patch } } : node)));
  };

  const handleMouseDown = (nodeId, e) => {
    e.stopPropagation(); setDraggedNodeId(nodeId); setIsDragging(true); setSelectedNodeId(nodeId);
    const rect = e.currentTarget.getBoundingClientRect();
    setDragOffset({ x: e.clientX - rect.left, y: e.clientY - rect.top });
  };

  const handleMouseMove = (e) => {
    if (!isDragging || !draggedNodeId || !canvasRef.current) return;
    const canvasRect = canvasRef.current.getBoundingClientRect();
    const nx = Math.max(10, e.clientX - canvasRect.left - dragOffset.x);
    const ny = Math.max(10, e.clientY - canvasRect.top - dragOffset.y);
    setNodes((prev) =>
      prev.map((n) => (n.id === draggedNodeId ? { ...n, x: nx, y: ny } : n)),
    );
  };

  const handleMouseUp = () => { setIsDragging(false); setDraggedNodeId(null); };

  const handlePortClick = (nodeId, portId, type, e) => {
    e.stopPropagation();
    if (type === 'output') setConnecting({ nodeId, portId, type });
    else if (type === 'input' && connecting && connecting.type === 'output') {
      if (connecting.nodeId !== nodeId && !edges.some(e => e.sourceNode === connecting.nodeId && e.sourcePort === connecting.portId && e.targetNode === nodeId)) {
        setEdges([...edges, { id: `edge_${Date.now()}`, sourceNode: connecting.nodeId, sourcePort: connecting.portId, targetNode: nodeId, targetPort: portId }]);
      }
      setConnecting(null);
    }
  };

  const selectedNode = nodes.find(n => n.id === selectedNodeId);
  const chipClass = (active) => (active ? t.chipOnSky : t.chipOff);
  const actionChipClass = (active) => (active ? t.chipOnEmerald : t.chipOff);

  return (
    <div
      className={`seo-flow-builder flex flex-col h-full w-full font-sans rounded-xl overflow-hidden shadow-md border transition-colors duration-200 ${t.root}`}
      data-theme={isDark ? 'dark' : 'light'}
      style={{ colorScheme: isDark ? 'dark' : 'light' }}
    >
      {/* HEADER */}
      <div className={`px-6 py-4 border-b flex items-center justify-between transition-colors duration-200 ${t.header}`}>
        <div className="flex items-center gap-4">
            <h1 className={`text-lg font-bold flex items-center gap-2 ${t.title}`}><Icons.Filter /> SEO Flow</h1>
            <input 
              type="text" 
              value={taskName} 
              onChange={(e) => setTaskName(e.target.value)} 
              className={`rounded px-3 py-1.5 text-sm w-64 transition-colors duration-200 focus:outline-none border ${t.input}`}
              placeholder="Workflow name..." 
            />
        </div>
        <button
          type="button"
          onClick={() => onSave(taskName, JSON.stringify({ nodes, edges }))}
          className={`${t.btnPrimary} px-4 py-2 rounded-lg text-sm font-bold transition-colors shadow-sm`}
        >
          Lưu Sơ Đồ Quy Trình
        </button>
      </div>

      <div className="flex flex-1 overflow-hidden">
        
        {/* SIDEBAR TOOLS */}
        <div className={`w-60 border-r p-4 flex flex-col gap-3 transition-colors duration-200 ${t.sidebar}`}>
          <h3 className={`text-xs font-bold uppercase tracking-wider mb-2 ${t.sidebarTitle}`}>Thêm Widget</h3>
          {[
            { type: 'article', label: 'Article (Input)', icon: <Icons.Article /> },
            { type: 'prompt', label: 'AI Prompt block', icon: <Icons.Prompt /> },
            { type: 'filter', label: 'Filter block', icon: <Icons.Filter /> },
            {
              type: 'action',
              label: 'Action',
              icon: <Icons.Play />,
              iconClass: 'text-rose-600 dark:text-rose-400 bg-rose-100 dark:bg-rose-500/10',
            },
          ].map(tool => (
            <button key={tool.type} onClick={() => addNode(tool.type)} className={`flex items-center gap-3 p-3 rounded-lg border text-left transition-all ${t.widgetBtn}`}>
              <div className={`p-1.5 rounded-md ${tool.iconClass ?? t.widgetIcon[tool.type]}`}>{tool.icon}</div>
              <span className="text-sm font-semibold">{tool.label}</span>
            </button>
          ))}
        </div>

        {/* CANVAS */}
        <div 
          ref={canvasRef} 
          onMouseMove={handleMouseMove} 
          onMouseUp={handleMouseUp} 
          onClick={() => setConnecting(null)} 
          className={`flex-1 relative overflow-hidden select-none transition-colors duration-200 ${t.canvas}`}
          style={{
            backgroundImage: t.gridImage,
            backgroundSize: '24px 24px',
          }}
        >
          {/* Edges */}
          <svg className="absolute inset-0 pointer-events-none w-full h-full z-0">
            {edges.map(edge => {
              const srcNode = nodes.find(n => n.id === edge.sourceNode);
              const tgtNode = nodes.find(n => n.id === edge.targetNode);
              if (!srcNode || !tgtNode) return null;
              const srcPorts = srcNode.type === 'prompt' ? getPromptOutputPorts(srcNode.data.promptId, mockPrompts, isDark) : [{id: 'out_main'}];
              const tgtOutPorts = tgtNode.type === 'prompt' ? getPromptOutputPorts(tgtNode.data.promptId, mockPrompts, isDark) : [{ id: 'out_main' }];
              const srcPortIndex = srcPorts.findIndex(p => p.id === edge.sourcePort);
              const srcNodeHeight = getDefaultNodeHeight(srcNode.type, srcPorts.length);
              const tgtNodeHeight = getDefaultNodeHeight(tgtNode.type, tgtOutPorts.length);
              const startX = getOutputPortCenterX(srcNode.x);
              const startY = srcNode.y + getOutputPortTop(srcNode.type, srcNodeHeight, srcPorts.length, Math.max(0, srcPortIndex));
              const endX = getInputPortCenterX(tgtNode.x);
              const endY = getInputPortCenterY(tgtNode.y, tgtNodeHeight);
              const cpOffset = Math.abs(endX - startX) * 0.5;
              const d = `M ${startX} ${startY} C ${startX + cpOffset} ${startY}, ${endX - cpOffset} ${endY}, ${endX} ${endY}`;
              
              const edgeColor = t.edgeColor;
              
              return (
                <g key={edge.id} className="pointer-events-auto cursor-pointer group">
                  <path d={d} fill="none" stroke={edgeColor} strokeWidth="3" className="hover:stroke-rose-500 transition-colors" onClick={(e) => { e.stopPropagation(); setEdges(edges.filter(x => x.id !== edge.id)); }} />
                  <circle cx={(startX + endX)/2} cy={(startY + endY)/2} r="4" fill={edgeColor} />
                </g>
              );
            })}
            
            {connecting && (() => {
              const srcNode = nodes.find(n => n.id === connecting.nodeId);
              if (!srcNode) return null;
              const connectPorts = srcNode.type === 'prompt' ? getPromptOutputPorts(srcNode.data.promptId, mockPrompts, isDark) : [{ id: 'out_main' }];
              const connectHeight = getDefaultNodeHeight(srcNode.type, connectPorts.length);
              const connectIndex = connectPorts.findIndex((p) => p.id === connecting.portId);
              const ix = Math.max(0, connectIndex);
              const startY = srcNode.y + getOutputPortTop(srcNode.type, connectHeight, connectPorts.length, ix);
              const startX = getOutputPortCenterX(srcNode.x);
              return <line x1={startX} y1={startY} x2={startX + 48} y2={startY} stroke="#f59e0b" strokeWidth="2" strokeDasharray="4" />;
            })()}
          </svg>

          {/* Nodes */}
          {nodes.map(node => {
            const isSelected = node.id === selectedNodeId;
            const nodeClass = `absolute w-[220px] rounded-xl border shadow-lg cursor-grab z-10 flex flex-col transition-colors duration-200 ${t.nodeBg} ${nodeBorderClass(node.type, isSelected, isDark)}`;
            const outputPorts = node.type === 'prompt'
              ? getPromptOutputPorts(node.data.promptId, mockPrompts, isDark)
              : [{ id: 'out_main', label: 'Connect', color: isDark ? 'bg-slate-500' : 'bg-gray-500' }];
            const nodeHeight = getDefaultNodeHeight(node.type, outputPorts.length);

            return (
              <div key={node.id} onMouseDown={(e) => handleMouseDown(node.id, e)} className={nodeClass} style={{ left: node.x, top: node.y, height: nodeHeight }}>
                
                {node.type !== 'article' && (
                  <div onClick={(e) => handlePortClick(node.id, 'in_main', 'input', e)} className={`absolute -left-3 top-1/2 -translate-y-1/2 w-5 h-5 border-2 rounded-full cursor-pointer hover:bg-emerald-500 hover:border-emerald-500 flex items-center justify-center z-20 ${t.portInput}`}><div className={`w-1.5 h-1.5 rounded-full ${t.portDot}`}></div></div>
                )}
                
                <div className={`p-3 flex items-center justify-between border-b ${t.nodeHeaderBorder}`}>
                  <div className={`flex items-center gap-2 font-bold text-sm ${t.nodeTitle}`}>
                    {node.type === 'article' && <Icons.Article />} {node.type === 'prompt' && <Icons.Prompt />}
                    {node.type === 'filter' && <Icons.Filter />} {node.type === 'action' && <Icons.Play />}
                    {node.title}
                  </div>
                  <button onClick={(e) => { e.stopPropagation(); deleteNode(node.id); }} className={t.trash}><Icons.Trash /></button>
                </div>
                
                <div className={`p-3 text-xs flex flex-col justify-center ${t.nodeBody}`}>
                  {node.type === 'article' && (
                    <div className="space-y-1">
                      <span>Hành động: <span className={`font-semibold ${t.accentEmerald}`}>{formatSelection(node.data.actions, actionLabels)}</span></span><br/>
                      {node.data.actions?.includes('add_comment_review') ? (
                        <>
                          <span>
                            Bình luận/review:{' '}
                            <span className={`font-semibold ${t.accentEmerald}`}>
                              {formatSelection(node.data.commentReview, commentReviewLabels)}
                            </span>
                          </span>
                          <br />
                        </>
                      ) : null}
                      <span>Loại: {formatSelection(node.data.postTypes)}</span><br/>
                      <span>Tax: {formatSelection(node.data.taxonomies)}</span>
                    </div>
                  )}
                  {node.type === 'prompt' && (
                    <>
                      <div className={`font-medium truncate ${t.accentViolet}`}>{mockPrompts.find(p => p.id === node.data.promptId)?.name}</div>
                      {node.data.aiModel ? (
                        <div className={`text-[10px] truncate mt-0.5 ${t.emptyHint}`}>{node.data.aiModel}</div>
                      ) : null}
                    </>
                  )}
                  {node.type === 'filter' && (
                    <div className="flex flex-col gap-1">
                      <span className="font-semibold text-amber-600 dark:text-amber-400">
                        {node.data.filterType === 'parse_outline' ? 'Extract outline' :
                         node.data.filterType === 'parse_keywords' ? 'Extract keywords' :
                         node.data.filterType === 'parse_faq' ? 'Extract FAQ' :
                         node.data.filterType === 'score_seo' ? 'SEO scoring' :
                         'Custom filter'}
                      </span>
                      {(!node.data.filterType || node.data.filterType === 'custom') && (
                        <span className="truncate text-[10px]">Logic: {node.data.rule || 'Not configured'}</span>
                      )}
                    </div>
                  )}
                  {node.type === 'action' && (
                    <div className="flex flex-col">
                      <span className={`font-medium ${isDark ? 'text-slate-200' : 'text-gray-800'}`}>
                        {node.data.actionType === 'edit_article'
                          ? 'Edit article'
                          : node.data.actionType === 'post_comment_review'
                            ? 'Post comment / review'
                            : node.data.actionType === 'save_vocabulary_research'
                              ? 'Save vocabulary research'
                              : 'Create new article'}
                      </span>
                      {node.data.isTrigger ? (
                        <span className="flex items-center gap-1 text-[10px] text-amber-500 font-bold mt-1.5 bg-amber-100 dark:bg-amber-500/10 px-2 py-0.5 rounded w-max border border-amber-200 dark:border-amber-500/20">
                          <Icons.Lightning /> Observer (Trigger)
                        </span>
                      ) : null}
                    </div>
                  )}
                </div>

                {outputPorts.map((port, index) => (
                  <div
                    key={port.id}
                    className="absolute -right-3 flex items-center flex-row-reverse"
                    style={{
                      top: getOutputPortTop(node.type, nodeHeight, outputPorts.length, index),
                      transform: 'translateY(-50%)',
                    }}
                  >
                    <div onClick={(e) => handlePortClick(node.id, port.id, 'output', e)} className={`w-5 h-5 rounded-full border-2 cursor-pointer flex items-center justify-center z-20 ${t.portBorder} ${connecting?.nodeId === node.id && connecting?.portId === port.id ? 'bg-amber-500 animate-pulse' : port.color}`}><div className="w-1.5 h-1.5 bg-white rounded-full"></div></div>
                    {node.type === 'prompt' && (
                      <div
                        className={`mr-3 max-w-[9.5rem] truncate text-[10px] font-semibold px-1.5 py-0.5 rounded border ${t.portLabel}`}
                        title={port.label}
                      >
                        {port.label}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            );
          })}
        </div>

        {/* RIGHT SETTINGS */}
        <div className={`w-80 border-l p-5 overflow-y-auto transition-colors duration-200 shadow-sm ${t.panel}`}>
          {selectedNode ? (
            <div className="space-y-5">
              <h3 className={`font-bold border-b pb-2 ${t.headingAccent}`}>Cấu hình: {selectedNode.title}</h3>
              
              {selectedNode.type === 'article' && (
                <div className="space-y-4">
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Hành động (Để trống = Lấy tất cả)</label>
                    <div className="flex flex-wrap gap-2">
                      {mockActions.map((act) => (
                        <button
                          type="button"
                          key={act.id}
                          onClick={() => {
                            const cur = selectedNode.data.actions || [];
                            updateNodeData(
                              selectedNode.id,
                              'actions',
                              cur.includes(act.id) ? cur.filter((x) => x !== act.id) : [...cur, act.id],
                            );
                          }}
                          className={`text-xs px-3 py-1.5 rounded-md border transition-colors shadow-sm ${actionChipClass(selectedNode.data.actions?.includes(act.id))}`}
                        >
                          {act.label}
                        </button>
                      ))}
                    </div>
                  </div>
                  {selectedNode.data.actions?.includes('add_comment_review') ? (
                    <div>
                      <label className={`text-xs block mb-1 ${t.label}`}>
                        Bình luận/review (Để trống = Tất cả)
                      </label>
                      <div className="flex flex-wrap gap-2">
                        {commentReviewFilters.map((opt) => (
                          <button
                            type="button"
                            key={opt.id}
                            onClick={() => {
                              const cur = selectedNode.data.commentReview || [];
                              updateNodeData(
                                selectedNode.id,
                                'commentReview',
                                cur.includes(opt.id) ? cur.filter((x) => x !== opt.id) : [...cur, opt.id],
                              );
                            }}
                            className={`text-xs px-3 py-1.5 rounded-md border transition-colors shadow-sm ${actionChipClass(selectedNode.data.commentReview?.includes(opt.id))}`}
                          >
                            {opt.label}
                          </button>
                        ))}
                      </div>
                    </div>
                  ) : null}
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Post Type (Để trống = Lấy tất cả)</label>
                    <div className="flex flex-wrap gap-2">
                      {mockPostTypes.map(pt => (
                        <button
                          type="button"
                          key={pt}
                          onClick={() => { const cur = selectedNode.data.postTypes || []; updateNodeData(selectedNode.id, 'postTypes', cur.includes(pt) ? cur.filter(x => x !== pt) : [...cur, pt]); }}
                          className={`text-xs px-3 py-1.5 rounded-md border transition-colors shadow-sm ${chipClass(selectedNode.data.postTypes?.includes(pt))}`}
                        >
                          {pt}
                        </button>
                      ))}
                    </div>
                  </div>
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Taxonomy (Để trống = Lấy tất cả)</label>
                    <div className="flex flex-wrap gap-2">
                      {mockTaxonomies.map(tax => (
                        <button
                          type="button"
                          key={tax}
                          onClick={() => { const cur = selectedNode.data.taxonomies || []; updateNodeData(selectedNode.id, 'taxonomies', cur.includes(tax) ? cur.filter(x => x !== tax) : [...cur, tax]); }}
                          className={`text-xs px-3 py-1.5 rounded-md border transition-colors shadow-sm ${chipClass(selectedNode.data.taxonomies?.includes(tax))}`}
                        >
                          {tax}
                        </button>
                      ))}
                    </div>
                  </div>
                </div>
              )}
              
              {selectedNode.type === 'prompt' && (
                <div className="space-y-4">
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Chọn Prompt thực thi</label>
                    <select
                      value={selectedNode.data.promptId}
                      onChange={(e) => {
                        const promptId = e.target.value;
                        const config = getPromptConfig(promptId);
                        updateNodeDataFields(selectedNode.id, {
                          promptId,
                          aiModel: config?.defaultModel ?? '',
                        });
                      }}
                      className={`w-full border rounded p-2 text-sm focus:outline-none transition-colors shadow-sm ${t.field}`}
                    >
                      {mockPrompts.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Model AI</label>
                    <select
                      value={selectedNode.data.aiModel || getPromptConfig(selectedNode.data.promptId)?.defaultModel || ''}
                      onChange={(e) => updateNodeData(selectedNode.id, 'aiModel', e.target.value)}
                      className={`w-full border rounded p-2 text-sm focus:outline-none transition-colors shadow-sm ${t.field}`}
                    >
                      {Object.entries(getPromptConfig(selectedNode.data.promptId)?.models || {}).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </select>
                    <p className={`text-[11px] mt-1.5 leading-relaxed ${t.emptyHint}`}>
                      Kết nối AI lấy từ Prompt đã chọn. Dùng biến <code>{'{{input}}'}</code> trong prompt để nhận kết quả từ edge nối vào.
                    </p>
                  </div>
                </div>
              )}

              {selectedNode.type === 'filter' && (
                <div className="space-y-4">
                  <div>
                    <label className="text-xs text-gray-500 dark:text-slate-400 block mb-1 font-semibold">Chức năng Xử lý / Lọc</label>
                    <select
                      value={selectedNode.data.filterType || 'custom'}
                      onChange={(e) => updateNodeData(selectedNode.id, 'filterType', e.target.value)}
                      className="w-full bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-700 rounded-md p-2 text-sm text-gray-800 dark:text-slate-200 focus:border-amber-500 outline-none shadow-sm transition-colors"
                    >
                      <option value="custom">Lọc điều kiện tùy chỉnh</option>
                      <option value="parse_outline">1. Bóc tách Dàn ý (Markdown -&gt; JSON)</option>
                      <option value="parse_keywords">2. Bóc tách Từ khóa (Markdown -&gt; JSON)</option>
                      <option value="parse_faq">3. Bóc tách FAQ</option>
                      <option value="score_seo">4. Chấm điểm SEO (FAQ + Bảng)</option>
                    </select>
                  </div>

                  {(!selectedNode.data.filterType || selectedNode.data.filterType === 'custom') && (
                    <div>
                      <label className="text-xs text-gray-500 dark:text-slate-400 block mb-1">Điều kiện lọc</label>
                      <input
                        type="text"
                        value={selectedNode.data.rule || ''}
                        onChange={(e) => updateNodeData(selectedNode.id, 'rule', e.target.value)}
                        placeholder="Enter filter logic (e.g. score > 80)..."
                        className="w-full bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-700 rounded-md p-2 text-sm text-gray-800 dark:text-white focus:outline-none focus:border-amber-500 transition-colors shadow-sm"
                      />
                    </div>
                  )}

                  {selectedNode.data.filterType === 'parse_outline' && (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg text-xs text-amber-700 dark:text-amber-400 leading-relaxed shadow-sm">
                      💡 <b>Parse Dàn ý:</b> Hệ thống sẽ sử dụng Parser để bóc tách các thẻ Heading (H2, H3) từ kết quả Markdown của AI thành cấu trúc JSON Outline chuẩn và đưa vào Meta Data.
                    </div>
                  )}

                  {selectedNode.data.filterType === 'parse_keywords' && (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg text-xs text-amber-700 dark:text-amber-400 leading-relaxed shadow-sm">
                      💡 <b>Parse Từ khóa:</b> Hệ thống sẽ đọc Markdown dạng list (### Category) và bóc tách thành các mảng từ khóa ngữ nghĩa (Synonyms, LSI...) lưu vào Meta Data.
                    </div>
                  )}

                  {selectedNode.data.filterType === 'parse_faq' && (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg text-xs text-amber-700 dark:text-amber-400 leading-relaxed shadow-sm">
                      💡 <b>Parse FAQ:</b> Bóc tách câu hỏi/trả lời (H3) và tự chấm +10 điểm nếu có FAQ hợp lệ.
                    </div>
                  )}

                  {selectedNode.data.filterType === 'score_seo' && (
                    <div className="bg-violet-50 dark:bg-violet-500/10 border border-violet-200 dark:border-violet-500/20 p-3 rounded-lg text-xs text-violet-700 dark:text-violet-300 leading-relaxed shadow-sm">
                      💡 <b>Chấm SEO:</b> FAQ (+10) và bảng Markdown Featured Snippet (&gt;=10 hàng, 2-5 cột, +10). Chạy sau khi AI sinh nội dung.
                    </div>
                  )}
                </div>
              )}

              {selectedNode.type === 'action' && (
                <div className="space-y-5">
                  <div className="bg-gray-50 dark:bg-slate-900/50 p-3 rounded-lg border border-gray-200 dark:border-slate-800">
                    <label className="flex items-center gap-3 cursor-pointer">
                      <div className="relative flex items-center">
                        <input
                          type="checkbox"
                          checked={selectedNode.data.isTrigger || false}
                          onChange={(e) => updateNodeData(selectedNode.id, 'isTrigger', e.target.checked)}
                          className="peer sr-only"
                        />
                        <div className="w-9 h-5 bg-gray-300 dark:bg-slate-700 peer-focus:outline-none rounded-full peer-checked:bg-amber-500 transition-colors" />
                        <div className="absolute left-[2px] top-[2px] bg-white w-4 h-4 rounded-full transition-transform peer-checked:translate-x-full shadow-sm" />
                      </div>
                      <div>
                        <span className="text-sm font-bold text-gray-800 dark:text-amber-400 block">Trigger (Observer)</span>
                        <span className="text-[10px] text-gray-500 dark:text-slate-500">Biến Widget thành bộ giám sát tự động</span>
                      </div>
                    </label>
                  </div>

                  <div>
                    <label className={`text-xs block mb-2 font-semibold ${t.label}`}>Thực thi Hành động</label>
                    <select
                      value={selectedNode.data.actionType || 'create_article'}
                      onChange={(e) => updateNodeData(selectedNode.id, 'actionType', e.target.value)}
                      className={`w-full border rounded-md p-2 text-sm focus:border-rose-500 focus:ring-1 focus:ring-rose-500 outline-none transition-colors shadow-sm ${t.field}`}
                    >
                      <option value="create_article">Tạo bài viết mới</option>
                      <option value="edit_article">Cập nhật / Sửa bài viết</option>
                      <option value="save_vocabulary_research">Lưu nghiên cứu từ vựng (Topic Cluster)</option>
                      <option value="post_comment_review">Đăng bình luận / review (WordPress)</option>
                    </select>
                  </div>

                  {selectedNode.data.actionType === 'post_comment_review' ? (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg">
                      <p className="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                        Đăng JSON comment/review lên WordPress qua plugin. Product: tự gán sao 5-5-4 nếu thiếu <code>star_ranking</code>.
                      </p>
                    </div>
                  ) : selectedNode.data.actionType === 'save_vocabulary_research' ? (
                    <div className="bg-violet-50 dark:bg-violet-500/10 border border-violet-200 dark:border-violet-500/20 p-3 rounded-lg">
                      <p className="text-xs text-violet-800 dark:text-violet-300 leading-relaxed">
                        Lưu từ khóa đã bóc tách (Khối Lọc → Bóc tách Từ khóa) vào bảng <code>keywords</code> theo cấu trúc Topic Cluster:
                        từ khóa chính (parent) + từ khóa con theo nhóm ngữ nghĩa, đồng thời gắn pivot <code>article_keyword</code>.
                      </p>
                    </div>
                  ) : (
                    <div className="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 p-3 rounded-lg">
                      <p className="text-xs text-emerald-700 dark:text-emerald-400 leading-relaxed">
                        💡 <b>Mẹo:</b> Hành động này có hỗ trợ cổng kết nối đầu ra (Output). Bạn có thể nối tiếp với các hành động khác (VD: Chia sẻ lên mạng xã hội) sau khi tạo bài viết xong.
                      </p>
                    </div>
                  )}
                </div>
              )}
            </div>
          ) : (<div className={`text-center mt-10 text-sm ${t.emptyHint}`}>Chọn một Node để cài đặt</div>)}
        </div>

      </div>
    </div>
  );
}
