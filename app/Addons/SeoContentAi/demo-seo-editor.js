import React, { useState, useEffect } from 'react';
import { 
  Type, 
  Image as ImageIcon, 
  Plus, 
  Trash2, 
  BarChart3, 
  CheckCircle2, 
  AlertCircle,
  GripVertical,
  Settings2
} from 'lucide-react';

/**
 * SEO Block Editor Prototype
 * Giao diện chỉnh sửa bài viết theo dạng block tích hợp phân tích SEO
 */
const App = () => {
  // State quản lý danh sách các block
  const [blocks, setBlocks] = useState([
    { id: '1', type: 'h1', content: 'Cách tối ưu SEO cho website Laravel 2026' },
    { id: '2', type: 'p', content: 'Trong bài viết này, chúng ta sẽ tìm hiểu về cách xây dựng hệ thống cắm rút...' },
  ]);

  const [keywords, setKeywords] = useState('laravel, seo, omnichannel');
  const [seoScore, setSeoScore] = useState(0);

  // Thêm block mới
  const addBlock = (type) => {
    const newBlock = {
      id: Math.random().toString(36).substr(2, 9),
      type: type,
      content: ''
    };
    setBlocks([...blocks, newBlock]);
  };

  // Cập nhật nội dung block
  const updateBlock = (id, content) => {
    setBlocks(blocks.map(b => b.id === id ? { ...b, content } : b));
  };

  // Xóa block
  const deleteBlock = (id) => {
    setBlocks(blocks.filter(b => b.id !== id));
  };

  // Tính toán điểm SEO (Logic giả lập)
  useEffect(() => {
    let score = 20;
    const allContent = blocks.map(b => b.content).join(' ').toLowerCase();
    const targetKeywords = keywords.split(',').map(k => k.trim().toLowerCase());

    targetKeywords.forEach(kw => {
      if (kw && allContent.includes(kw)) score += 20;
    });

    if (blocks.some(b => b.type === 'h1' && b.content.length > 10)) score += 20;
    setSeoScore(Math.min(score, 100));
  }, [blocks, keywords]);

  return (
    <div className="flex flex-col md:flex-row min-h-screen bg-slate-900 text-slate-100 font-sans">
      
      {/* Main Editor Area */}
      <div className="flex-1 p-6 md:p-12 overflow-y-auto">
        <div className="max-w-3xl mx-auto space-y-6">
          <header className="mb-12">
            <input 
              className="bg-transparent border-none text-4xl font-bold w-full focus:outline-none focus:ring-0 placeholder-slate-700"
              placeholder="Nhập tiêu đề dự án..."
            />
          </header>

          <div className="space-y-4">
            {blocks.map((block) => (
              <div key={block.id} className="group relative flex items-start gap-4">
                <div className="opacity-0 group-hover:opacity-100 flex flex-col pt-2 transition-opacity">
                  <GripVertical className="w-4 h-4 text-slate-600 cursor-grab" />
                </div>

                <div className="flex-1">
                  {block.type === 'h1' ? (
                    <input
                      className="w-full bg-slate-800/50 border-l-4 border-cyan-500 p-4 text-2xl font-bold focus:bg-slate-800 transition-all outline-none rounded-r-lg"
                      value={block.content}
                      onChange={(e) => updateBlock(block.id, e.target.value)}
                      placeholder="Heading 1..."
                    />
                  ) : (
                    <textarea
                      className="w-full bg-slate-800/50 p-4 min-h-[100px] leading-relaxed focus:bg-slate-800 transition-all outline-none rounded-lg resize-none"
                      value={block.content}
                      onChange={(e) => updateBlock(block.id, e.target.value)}
                      placeholder="Viết nội dung tại đây..."
                    />
                  )}
                </div>

                <button 
                  onClick={() => deleteBlock(block.id)}
                  className="opacity-0 group-hover:opacity-100 p-2 text-slate-500 hover:text-red-400 transition-all"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            ))}
          </div>

          {/* Quick Actions */}
          <div className="flex items-center gap-4 pt-8 border-t border-slate-800">
            <button onClick={() => addBlock('p')} className="flex items-center gap-2 px-4 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm transition-colors">
              <Plus className="w-4 h-4" /> Đoạn văn
            </button>
            <button onClick={() => addBlock('h1')} className="flex items-center gap-2 px-4 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm transition-colors">
              <Type className="w-4 h-4" /> Heading
            </button>
            <button className="flex items-center gap-2 px-4 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm transition-colors">
              <ImageIcon className="w-4 h-4" /> Hình ảnh
            </button>
          </div>
        </div>
      </div>

      {/* SEO Sidebar */}
      <div className="w-full md:w-80 bg-slate-950 border-l border-slate-800 p-6 space-y-8">
        <div>
          <h3 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-slate-500 mb-4">
            <BarChart3 className="w-4 h-4" /> Phân tích SEO
          </h3>
          <div className="relative pt-1">
            <div className="flex mb-2 items-center justify-between">
              <div>
                <span className="text-xs font-semibold inline-block py-1 px-2 uppercase rounded-full text-cyan-500 bg-cyan-500/10">
                  Sức khỏe bài viết
                </span>
              </div>
              <div className="text-right">
                <span className="text-xs font-semibold inline-block text-cyan-500">
                  {seoScore}%
                </span>
              </div>
            </div>
            <div className="overflow-hidden h-2 mb-4 text-xs flex rounded bg-slate-800">
              <div style={{ width: `${seoScore}%` }} className="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-cyan-500 transition-all duration-500"></div>
            </div>
          </div>
        </div>

        <div>
          <h3 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-slate-500 mb-4">
            <Settings2 className="w-4 h-4" /> Từ khóa mục tiêu
          </h3>
          <input 
            className="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm focus:border-cyan-500 outline-none"
            value={keywords}
            onChange={(e) => setKeywords(e.target.value)}
            placeholder="Nhập từ khóa cách nhau bằng dấu phẩy..."
          />
        </div>

        <div className="space-y-4">
          <h3 className="text-sm font-semibold text-slate-500">Checklist</h3>
          <div className="space-y-3">
            <div className="flex items-center gap-3 text-sm">
              {blocks.some(b => b.type === 'h1') ? <CheckCircle2 className="w-4 h-4 text-green-500" /> : <AlertCircle className="w-4 h-4 text-red-500" />}
              Có ít nhất 1 Heading H1
            </div>
            <div className="flex items-center gap-3 text-sm">
              {blocks.length >= 3 ? <CheckCircle2 className="w-4 h-4 text-green-500" /> : <AlertCircle className="w-4 h-4 text-amber-500" />}
              Độ dài bài viết (> 3 blocks)
            </div>
            <div className="flex items-center gap-3 text-sm">
              {keywords.split(',').some(k => blocks.map(b => b.content).join(' ').toLowerCase().includes(k.trim().toLowerCase())) 
                ? <CheckCircle2 className="w-4 h-4 text-green-500" /> 
                : <AlertCircle className="w-4 h-4 text-red-500" />}
              Từ khóa xuất hiện trong bài
            </div>
          </div>
        </div>

        <button className="w-full py-3 bg-cyan-600 hover:bg-cyan-500 text-white font-bold rounded-xl transition-colors shadow-lg shadow-cyan-900/20">
          Lưu bài viết
        </button>
      </div>
    </div>
  );
};

export default App;