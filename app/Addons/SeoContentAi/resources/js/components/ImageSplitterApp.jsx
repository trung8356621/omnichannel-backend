import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { fetchSplitterSource, saveSplitPiecesToLibrary } from '../utils/seoMediaApi';

function loadImage(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Không tải được ảnh.'));
        img.src = src;
    });
}

function clampInt(value, min, max) {
    const n = Number.parseInt(String(value), 10);
    if (Number.isNaN(n)) return min;
    return Math.max(min, Math.min(max, n));
}

function absoluteImageUrl(url) {
    if (!url) return '';
    if (url.startsWith('http://') || url.startsWith('https://')) {
        return url;
    }
    if (url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
}

export default function ImageSplitterApp({
    siteId = null,
    articleId = null,
    seoMediaId = null,
    wpAttachmentId = null,
    slug = '',
    embedded = false,
    fallbackImageUrl = '',
    splitPayload = null,
}) {
    const resultPaneRef = useRef(null);
    const splitPayloadRef = useRef(splitPayload);
    splitPayloadRef.current = splitPayload;
    const [imageSrc, setImageSrc] = useState('');
    const [imageName, setImageName] = useState('image');
    const [sourceMeta, setSourceMeta] = useState({
        seoMediaId: null,
        wpAttachmentId: null,
        resolvedSiteId: null,
    });
    const [imgNatural, setImgNatural] = useState({ width: 0, height: 0 });
    const [rows, setRows] = useState(3);
    const [cols, setCols] = useState(2);
    const [pieces, setPieces] = useState([]);
    const [isSplitting, setIsSplitting] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [isLoadingSource, setIsLoadingSource] = useState(true);
    const [saveMessage, setSaveMessage] = useState('');
    const [error, setError] = useState('');
    const laravelId = Number(seoMediaId ?? 0);
    const wpId = Number(wpAttachmentId ?? 0);
    const hasSourceId = laravelId > 0 || wpId > 0;

    const applyExternalPieces = useCallback((incoming) => {
        if (!incoming?.length) {
            return;
        }

        const addedCount = incoming.length;
        setPieces((prev) => {
            const normalized = incoming.map((piece, i) => ({
                ...piece,
                id: piece.id ?? `${Date.now()}-crop-${i}-${Math.random()}`,
            }));
            return [...prev, ...normalized];
        });
        setSaveMessage(`Đã thêm ${addedCount} ảnh vào «Ảnh sau khi split».`);
        setError('');

        requestAnimationFrame(() => {
            resultPaneRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }, []);

    const loadImageFromUrl = async (displayUrl, meta = {}) => {
        const image = await loadImage(displayUrl);
        setImageSrc(displayUrl);
        setImageName(meta.name || 'image');
        setImgNatural({ width: image.naturalWidth, height: image.naturalHeight });
        setSourceMeta({
            seoMediaId: meta.seoMediaId ?? (laravelId > 0 ? laravelId : null),
            wpAttachmentId: meta.wpAttachmentId ?? (wpId > 0 ? wpId : null),
            resolvedSiteId: meta.resolvedSiteId ?? siteId ?? null,
        });

        if (!splitPayloadRef.current?.pieces?.length) {
            setPieces((prev) => {
                prev.forEach((piece) => URL.revokeObjectURL(piece.url));
                return [];
            });
        }
    };

    useEffect(() => {
        return () => {
            pieces.forEach((piece) => URL.revokeObjectURL(piece.url));
        };
    }, [pieces]);

    useEffect(() => {
        let cancelled = false;

        const loadSource = async () => {
            setIsLoadingSource(true);
            setError('');
            setSaveMessage('');

            if (!hasSourceId) {
                setError('Thiếu seo_media_id hoặc wp_attachment_id — mở từ thư viện, bài viết hoặc kết quả AI.');
                setIsLoadingSource(false);
                return;
            }

            const directUrl = absoluteImageUrl(fallbackImageUrl);
            if (directUrl && laravelId > 0) {
                try {
                    await loadImageFromUrl(directUrl, {
                        seoMediaId: laravelId,
                        wpAttachmentId: wpId > 0 ? wpId : null,
                        resolvedSiteId: siteId ?? null,
                    });
                } catch (e) {
                    if (!cancelled) {
                        setError(e.message || 'Không tải được ảnh nguồn.');
                    }
                } finally {
                    if (!cancelled) {
                        setIsLoadingSource(false);
                    }
                }

                return;
            }

            try {
                const resolved = await fetchSplitterSource({
                    siteId,
                    seoMediaId: laravelId > 0 ? laravelId : null,
                    wpAttachmentId: wpId > 0 ? wpId : null,
                    slug,
                });

                if (cancelled) return;

                const displayUrl = absoluteImageUrl(resolved.url);
                await loadImageFromUrl(displayUrl, {
                    name: resolved.name || 'image',
                    seoMediaId: resolved.seo_media_id > 0 ? resolved.seo_media_id : null,
                    wpAttachmentId: resolved.wp_attachment_id > 0 ? resolved.wp_attachment_id : null,
                    resolvedSiteId: resolved.site_id ?? siteId ?? null,
                });
            } catch (e) {
                if (!cancelled) {
                    setError(e.message || 'Không tải được ảnh nguồn.');
                }
            } finally {
                if (!cancelled) {
                    setIsLoadingSource(false);
                }
            }
        };

        loadSource();

        return () => {
            cancelled = true;
        };
    }, [siteId, articleId, seoMediaId, wpAttachmentId, slug, hasSourceId, laravelId, wpId, fallbackImageUrl]);

    const effectiveSiteId = sourceMeta.resolvedSiteId ?? siteId ?? null;
    const hasImage = imageSrc !== '';

    useEffect(() => {
        if (splitPayload?.pieces?.length && hasImage) {
            applyExternalPieces(splitPayload.pieces);
        }
    }, [splitPayload?.id, hasImage, applyExternalPieces, splitPayload]);
    const canSave =
        pieces.length > 0 &&
        effectiveSiteId != null &&
        (sourceMeta.seoMediaId ?? 0) > 0 &&
        !isSaving;

    const gridLines = useMemo(() => {
        const vertical = [];
        const horizontal = [];
        for (let i = 1; i < cols; i += 1) {
            vertical.push((i / cols) * 100);
        }
        for (let i = 1; i < rows; i += 1) {
            horizontal.push((i / rows) * 100);
        }
        return { vertical, horizontal };
    }, [rows, cols]);

    const splitImage = async () => {
        if (!hasImage) {
            setError('Chưa có ảnh nguồn.');
            return;
        }

        setIsSplitting(true);
        setError('');
        setSaveMessage('');

        try {
            const image = await loadImage(imageSrc);
            const nextPieces = [];

            for (let r = 0; r < rows; r += 1) {
                for (let c = 0; c < cols; c += 1) {
                    const x0 = Math.round((c * image.naturalWidth) / cols);
                    const x1 = Math.round(((c + 1) * image.naturalWidth) / cols);
                    const y0 = Math.round((r * image.naturalHeight) / rows);
                    const y1 = Math.round(((r + 1) * image.naturalHeight) / rows);

                    const width = Math.max(1, x1 - x0);
                    const height = Math.max(1, y1 - y0);
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(image, x0, y0, width, height, 0, 0, width, height);

                    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 1));
                    if (!blob) continue;
                    const objectUrl = URL.createObjectURL(blob);
                    const index = r * cols + c + 1;
                    nextPieces.push({
                        id: `${Date.now()}-${r}-${c}-${Math.random()}`,
                        row: r + 1,
                        col: c + 1,
                        width,
                        height,
                        blob,
                        url: objectUrl,
                        filename: `${imageName}-r${r + 1}-c${c + 1}-${index}.png`,
                    });
                }
            }

            const addedCount = nextPieces.length;
            setPieces((prev) => [...prev, ...nextPieces]);
            setSaveMessage(`Đã thêm ${addedCount} ảnh vào «Ảnh sau khi split».`);
        } catch (e) {
            setError(e.message || 'Không thể tách ảnh.');
        } finally {
            setIsSplitting(false);
        }
    };

    const removePiece = (id) => {
        setPieces((prev) => {
            const next = [];
            for (const piece of prev) {
                if (piece.id === id) {
                    URL.revokeObjectURL(piece.url);
                } else {
                    next.push(piece);
                }
            }
            return next;
        });
    };

    const saveToLibrary = async () => {
        if (!canSave) {
            if (!effectiveSiteId) {
                setError('Thiếu site_id — mở lại từ thư viện hoặc chọn tên miền.');
            } else if (!(sourceMeta.seoMediaId > 0)) {
                setError('Thiếu seo_media_id — không thể xóa ảnh gốc sau khi lưu.');
            }
            return;
        }

        setIsSaving(true);
        setError('');
        setSaveMessage('');

        try {
            const data = await saveSplitPiecesToLibrary({
                siteId: effectiveSiteId,
                articleId,
                originalSeoMediaId: sourceMeta.seoMediaId,
                pieces,
            });

            setPieces((prev) => {
                prev.forEach((piece) => URL.revokeObjectURL(piece.url));
                return [];
            });
            setImageSrc('');
            setImageName('image');
            setImgNatural({ width: 0, height: 0 });
            setSourceMeta({
                seoMediaId: null,
                wpAttachmentId: null,
                resolvedSiteId: effectiveSiteId,
            });

            setSaveMessage(data.message ?? `Đã lưu ${data.saved?.length ?? 0} ảnh.`);

            if (window.opener) {
                window.opener.postMessage(
                    {
                        type: 'seo-image-splitter-saved',
                        saved: data.saved ?? [],
                        deletedOriginal: !!data.deleted_original,
                    },
                    window.location.origin,
                );
            }
        } catch (e) {
            setError(e.message || 'Không lưu được ảnh.');
        } finally {
            setIsSaving(false);
        }
    };

    const hasResults = pieces.length > 0;

    return (
        <div
            className={`seo-image-splitter${embedded ? ' seo-image-splitter--embedded' : ''}${
                hasResults ? ' seo-image-splitter--has-results' : ''
            }`}
        >
            <div className="splitter-controls">
                <div className="splitter-row">
                    <label>
                        Hàng
                        <input
                            type="number"
                            min={1}
                            max={12}
                            value={rows}
                            onChange={(e) => setRows(clampInt(e.target.value, 1, 12))}
                        />
                    </label>
                    <label>
                        Cột
                        <input
                            type="number"
                            min={1}
                            max={12}
                            value={cols}
                            onChange={(e) => setCols(clampInt(e.target.value, 1, 12))}
                        />
                    </label>
                    <button
                        type="button"
                        className="btn-primary"
                        disabled={!hasImage || isSplitting || isLoadingSource}
                        onClick={splitImage}
                    >
                        {isSplitting ? 'Đang tách…' : 'Tách ảnh'}
                    </button>
                </div>

                {isLoadingSource && <div className="hint">Đang tải ảnh theo ID…</div>}
                {imgNatural.width > 0 && (
                    <div className="hint">
                        Kích thước ảnh gốc: {imgNatural.width} x {imgNatural.height}
                        {sourceMeta.seoMediaId ? ` · Laravel #${sourceMeta.seoMediaId}` : ''}
                        {sourceMeta.wpAttachmentId ? ` · WP #${sourceMeta.wpAttachmentId}` : ''}
                    </div>
                )}
                {!effectiveSiteId && hasImage && (
                    <div className="hint">
                        Chưa có site_id — mở từ thư viện hoặc test prompt (có tên miền) để lưu được.
                    </div>
                )}
                {saveMessage && <div className="splitter-success">{saveMessage}</div>}
                {error && <div className="splitter-error">{error}</div>}
            </div>

            <div className="splitter-workspace">
                <div className="splitter-preview-pane">
                    <h3>Ảnh gốc + Grid</h3>
                    {isLoadingSource ? (
                        <div className="empty-card">Đang tải ảnh từ Laravel / WordPress…</div>
                    ) : !hasImage ? (
                        <div className="empty-card">
                            Cần <code>seo_media_id</code> hoặc <code>wp_attachment_id</code> trên URL.
                        </div>
                    ) : (
                        <div className="grid-preview">
                            <img src={imageSrc} alt="Source" />
                            {gridLines.vertical.map((left) => (
                                <span key={`v-${left}`} className="grid-line v" style={{ left: `${left}%` }} />
                            ))}
                            {gridLines.horizontal.map((top) => (
                                <span key={`h-${top}`} className="grid-line h" style={{ top: `${top}%` }} />
                            ))}
                        </div>
                    )}
                </div>

                <div className="splitter-result-pane" ref={resultPaneRef}>
                    <div className="result-header">
                        <h3>Ảnh sau khi split ({pieces.length})</h3>
                        {pieces.length > 0 && (
                            <button
                                type="button"
                                className="btn-save"
                                disabled={!canSave}
                                onClick={saveToLibrary}
                            >
                                {isSaving ? 'Đang lưu…' : 'Lưu vào thư viện'}
                            </button>
                        )}
                    </div>

                    {pieces.length === 0 ? (
                        <div className="empty-card">Bấm Split → Lưu vào thư viện (ảnh gốc Laravel sẽ bị xóa).</div>
                    ) : (
                        <div className="piece-grid">
                            {pieces.map((piece, idx) => (
                                <div key={piece.id} className="piece-card">
                                    <img src={piece.url} alt={`Piece ${idx + 1}`} />
                                    <div className="piece-meta">
                                        <strong>#{idx + 1}</strong> r{piece.row} c{piece.col} · {piece.width}x{piece.height}
                                    </div>
                                    <div className="piece-actions">
                                        <button type="button" className="btn-danger" onClick={() => removePiece(piece.id)}>
                                            Bỏ khỏi danh sách
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
