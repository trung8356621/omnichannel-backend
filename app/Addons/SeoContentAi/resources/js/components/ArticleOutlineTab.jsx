import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, Loader2, Pencil, RefreshCw, ShieldAlert, Sparkles } from 'lucide-react';

const outlineUrl = (articleId) => `/api/seo/articles/${articleId}/outline`;
const checkDuplicatesUrl = (articleId) => `/api/seo/articles/${articleId}/outline/check-duplicates`;
const headingUrl = (articleId, headingId) => `/api/seo/articles/${articleId}/outline/${headingId}`;
const generateUrl = (articleId, headingId) =>
    `/api/seo/articles/${articleId}/outline/${headingId}/generate`;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            ...(options.headers ?? {}),
        },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        const message =
            response.status === 419
                ? 'Phiên đăng nhập hết hạn — tải lại trang rồi thử lại.'
                : (data.message ?? 'Yêu cầu thất bại.');
        throw new Error(message);
    }

    return data;
}

function normalizeOutlineHeadingText(text) {
    return String(text ?? '').replace(/\s+/g, ' ').trim();
}

/** Tìm node outline theo level + text, kèm groupId (H2 container). */
function findOutlineNodeWithGroup(nodes, level, headingText, groupId = null) {
    const normalized = normalizeOutlineHeadingText(headingText);

    for (const node of nodes) {
        const ownGroupId = node.level <= 2 ? node.id : groupId;
        if (
            node.level === level &&
            normalizeOutlineHeadingText(node.heading_text) === normalized
        ) {
            return { node, groupId: ownGroupId };
        }
        if (Array.isArray(node.children) && node.children.length > 0) {
            const found = findOutlineNodeWithGroup(node.children, level, headingText, ownGroupId);
            if (found) {
                return found;
            }
        }
    }

    return null;
}

/** Update 1 node (theo id) trong tree bất kỳ độ sâu. */
function patchTreeNode(nodes, headingId, patch) {
    return nodes.map((node) => {
        if (node.id === headingId) {
            return { ...node, ...patch };
        }
        if (Array.isArray(node.children) && node.children.length > 0) {
            return { ...node, children: patchTreeNode(node.children, headingId, patch) };
        }

        return node;
    });
}

/**
 * Block 1 heading: click nhảy editor, double-click focus nhóm, icon Pencil/Sparkles để sửa/gen.
 */
function HeadingBlock({
    node,
    groupId,
    activeGroupId,
    activeHeadingId,
    editingHeadingId,
    onEditingHeadingEnd,
    onSelectGroup,
    onJumpToEditor,
    onSaveText,
    onGenerate,
    busyHeadingId,
    duplicateInfo = null,
    onSelectDuplicate,
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(node.heading_text);
    const inputRef = useRef(null);
    const clickTimerRef = useRef(null);
    const isBusy = busyHeadingId === node.id;
    const isHeadingFocused = activeHeadingId === node.id;

    useEffect(() => {
        if (!editing) {
            setDraft(node.heading_text);
        }
    }, [node.heading_text, editing]);

    useEffect(() => {
        if (editing) {
            inputRef.current?.focus();
            inputRef.current?.select();
        }
    }, [editing]);

    useEffect(() => {
        if (editingHeadingId === node.id && !editing) {
            setEditing(true);
        }
    }, [editingHeadingId, node.id, editing]);

    const endEditing = useCallback(() => {
        setEditing(false);
        if (editingHeadingId === node.id) {
            onEditingHeadingEnd?.();
        }
    }, [editingHeadingId, node.id, onEditingHeadingEnd]);

    const commitDraft = useCallback(() => {
        endEditing();
        const next = draft.replace(/\s+/g, ' ').trim();
        if (next === '' || next === node.heading_text) {
            setDraft(node.heading_text);
            return;
        }
        onSaveText(node, next);
    }, [draft, endEditing, node, onSaveText]);

    useEffect(
        () => () => {
            if (clickTimerRef.current) {
                window.clearTimeout(clickTimerRef.current);
            }
        },
        [],
    );

    return (
        <div
            data-outline-heading-id={node.id}
            className={[
                'seo-outline-block',
                `seo-outline-block--h${node.level}`,
                isHeadingFocused ? 'is-heading-focused' : '',
                isBusy ? 'is-busy' : '',
                duplicateInfo ? 'is-duplicate' : '',
            ]
                .filter(Boolean)
                .join(' ')}
            onClick={(e) => {
                e.stopPropagation();
                if (editing || isBusy) {
                    return;
                }
                if (clickTimerRef.current) {
                    window.clearTimeout(clickTimerRef.current);
                }
                clickTimerRef.current = window.setTimeout(() => {
                    clickTimerRef.current = null;
                    onSelectGroup(groupId, node.id);
                    onJumpToEditor?.(node);
                }, 220);
            }}
            onDoubleClick={(e) => {
                e.stopPropagation();
                if (clickTimerRef.current) {
                    window.clearTimeout(clickTimerRef.current);
                    clickTimerRef.current = null;
                }
                onSelectGroup(groupId, node.id);
            }}
        >
            <div className="seo-outline-block__main">
                <span className="seo-outline-block__level">H{node.level}</span>
                {editing ? (
                    <input
                        ref={inputRef}
                        type="text"
                        className="seo-outline-block__input"
                        value={draft}
                        maxLength={255}
                        onChange={(e) => setDraft(e.target.value)}
                        onBlur={commitDraft}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                commitDraft();
                            }
                            if (e.key === 'Escape') {
                                setDraft(node.heading_text);
                                endEditing();
                            }
                        }}
                    />
                ) : (
                    <span className="seo-outline-block__text" title="Click: nhảy tới editor · Double-click: focus nhóm">
                        {node.heading_text}
                    </span>
                )}
            </div>
            {duplicateInfo && !editing ? (
                <button
                    type="button"
                    className="seo-outline-block__duplicate-warn"
                    title={`Trùng "${duplicateInfo.matched_heading}" trong bài "${duplicateInfo.matched_article_title}" — click để xem dàn ý bài này`}
                    onClick={(e) => {
                        e.stopPropagation();
                        onSelectDuplicate?.(duplicateInfo);
                    }}
                >
                    <AlertTriangle size={13} strokeWidth={1.75} />
                    Trùng với bài #{duplicateInfo.matched_article_id}
                    {duplicateInfo.match_type === 'semantic' ? ' (đồng nghĩa)' : ''}
                </button>
            ) : null}
            {!editing ? (
                <div className="seo-outline-block__actions-row">
                    {!isHeadingFocused ? (
                        <button
                            type="button"
                            className="seo-outline-block__action-btn"
                            onClick={(e) => {
                                e.stopPropagation();
                                if (!isBusy) {
                                    setEditing(true);
                                }
                            }}
                        >
                            <span className="seo-outline-block__action-label">Sửa tay</span>
                            <Pencil size={14} strokeWidth={1.75} />
                        </button>
                    ) : null}
                    <button
                        type="button"
                        className="seo-outline-block__action-btn seo-outline-block__action-btn--ai"
                        disabled={isBusy}
                        onClick={(e) => {
                            e.stopPropagation();
                            if (!isBusy) {
                                onGenerate(node);
                            }
                        }}
                    >
                        <span className="seo-outline-block__action-label">
                            {isBusy ? 'Đang gen...' : 'AI gen'}
                        </span>
                        {isBusy ? (
                            <Loader2 size={14} strokeWidth={1.75} className="seo-outline-spin" />
                        ) : (
                            <Sparkles size={14} strokeWidth={1.75} />
                        )}
                    </button>
                </div>
            ) : null}
        </div>
    );
}

/** Render đệ quy. H2 là group container — click vào sẽ highlight cả nhóm con. */
function OutlineTree({
    nodes,
    groupId = null,
    activeGroupId,
    activeHeadingId,
    editingHeadingId,
    onEditingHeadingEnd,
    onSelectGroup,
    onJumpToEditor,
    onSaveText,
    onGenerate,
    busyHeadingId,
    duplicateByHeadingId = null,
    onSelectDuplicate,
}) {
    return (
        <>
            {nodes.map((node) => {
                const ownGroupId = node.level <= 2 ? node.id : groupId;
                const hasChildren = Array.isArray(node.children) && node.children.length > 0;
                const isSectionFocused =
                    node.level <= 2 &&
                    activeHeadingId === node.id;

                return (
                    <div
                        key={node.id}
                        className={[
                            'seo-outline-group',
                            node.level <= 2 ? 'seo-outline-group--root' : '',
                            isSectionFocused ? 'is-active' : '',
                        ]
                            .filter(Boolean)
                            .join(' ')}
                    >
                        <HeadingBlock
                            node={node}
                            groupId={ownGroupId}
                            activeGroupId={activeGroupId}
                            activeHeadingId={activeHeadingId}
                            editingHeadingId={editingHeadingId}
                            onEditingHeadingEnd={onEditingHeadingEnd}
                            onSelectGroup={onSelectGroup}
                            onJumpToEditor={onJumpToEditor}
                            onSaveText={onSaveText}
                            onGenerate={onGenerate}
                            busyHeadingId={busyHeadingId}
                            duplicateInfo={duplicateByHeadingId?.get(node.id) ?? null}
                            onSelectDuplicate={onSelectDuplicate}
                        />
                        {hasChildren && (
                            <div className="seo-outline-children">
                                <OutlineTree
                                    nodes={node.children}
                                    groupId={ownGroupId}
                                    activeGroupId={activeGroupId}
                                    activeHeadingId={activeHeadingId}
                                    editingHeadingId={editingHeadingId}
                                    onEditingHeadingEnd={onEditingHeadingEnd}
                                    onSelectGroup={onSelectGroup}
                                    onJumpToEditor={onJumpToEditor}
                                    onSaveText={onSaveText}
                                    onGenerate={onGenerate}
                                    busyHeadingId={busyHeadingId}
                                    duplicateByHeadingId={duplicateByHeadingId}
                                    onSelectDuplicate={onSelectDuplicate}
                                />
                            </div>
                        )}
                    </div>
                );
            })}
        </>
    );
}

/**
 * Cây outline chỉ đọc — hiển thị dàn ý của bài viết bị trùng (cột phải).
 * `activeHeadingId`: heading bị trùng -> highlight; các heading khác bị làm mờ.
 */
function ReadOnlyOutlineTree({ nodes, activeHeadingId = null }) {
    return (
        <>
            {nodes.map((node) => {
                const isMatched = activeHeadingId !== null && node.id === activeHeadingId;
                const isDimmed = activeHeadingId !== null && !isMatched;

                return (
                    <div
                        key={node.id}
                        className={[
                            'seo-outline-group',
                            node.level <= 2 ? 'seo-outline-group--root' : '',
                        ]
                            .filter(Boolean)
                            .join(' ')}
                    >
                        <div
                            data-readonly-heading-id={node.id}
                            className={[
                                'seo-outline-block',
                                `seo-outline-block--h${node.level}`,
                                'seo-outline-block--readonly',
                                isMatched ? 'is-matched' : '',
                                isDimmed ? 'is-dimmed' : '',
                            ]
                                .filter(Boolean)
                                .join(' ')}
                        >
                            <div className="seo-outline-block__main">
                                <span className="seo-outline-block__level">H{node.level}</span>
                                <span className="seo-outline-block__text">{node.heading_text}</span>
                            </div>
                        </div>
                        {Array.isArray(node.children) && node.children.length > 0 ? (
                            <div className="seo-outline-children">
                                <ReadOnlyOutlineTree
                                    nodes={node.children}
                                    activeHeadingId={activeHeadingId}
                                />
                            </div>
                        ) : null}
                    </div>
                );
            })}
        </>
    );
}

/**
 * Tab "Outline / Dàn ý" — quản lý TOC bóc tách từ bài viết.
 */
export default function ArticleOutlineTab({
    articleId,
    headingCommand = null,
    onOutlineLoaded,
    onHeadingTextChange,
    onJumpToEditorHeading,
    onNotify,
}) {
    const [tree, setTree] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [activeGroupId, setActiveGroupId] = useState(null);
    const [activeHeadingId, setActiveHeadingId] = useState(null);
    const [editingHeadingId, setEditingHeadingId] = useState(null);
    const [busyHeadingId, setBusyHeadingId] = useState(null);
    const [isDuplicateModeActive, setIsDuplicateModeActive] = useState(false);
    const [duplicates, setDuplicates] = useState([]);
    const [isLoadingCheck, setIsLoadingCheck] = useState(false);
    const [selectedDuplicateArticleId, setSelectedDuplicateArticleId] = useState(null);
    const [activeMatchedHeadingId, setActiveMatchedHeadingId] = useState(null);
    const [compareTree, setCompareTree] = useState([]);
    const [compareArticleTitle, setCompareArticleTitle] = useState('');
    const [compareLoading, setCompareLoading] = useState(false);
    const [compareError, setCompareError] = useState('');

    const showDuplicateSplit = isDuplicateModeActive && duplicates.length > 0;

    /** Map heading id (bài hiện tại) -> thông tin trùng để highlight block. */
    const duplicateByHeadingId = useMemo(() => {
        if (!isDuplicateModeActive) {
            return null;
        }

        const map = new Map();
        for (const dup of duplicates) {
            const key = Number(dup.original_key);
            if (Number.isFinite(key) && !map.has(key)) {
                map.set(key, dup);
            }
        }
        return map;
    }, [duplicates, isDuplicateModeActive]);

    const handleSelectGroup = useCallback((groupId, headingId = null) => {
        setActiveGroupId(groupId);
        setActiveHeadingId(headingId);
    }, []);

    const handleJumpToEditor = useCallback(
        (node) => {
            onJumpToEditorHeading?.(node);
        },
        [onJumpToEditorHeading],
    );

    const notify = useCallback(
        (title, body, status = 'success') => {
            onNotify?.({ title, body, status });
        },
        [onNotify],
    );

    const loadOutline = useCallback(async () => {
        setLoading(true);
        setError('');
        // Outline đổi -> thoát chế độ dò trùng.
        setIsDuplicateModeActive(false);
        setDuplicates([]);
        setSelectedDuplicateArticleId(null);
        setActiveMatchedHeadingId(null);
        setCompareTree([]);
        setCompareArticleTitle('');
        setCompareError('');
        try {
            const data = await requestJson(outlineUrl(articleId));
            const outline = Array.isArray(data.outline) ? data.outline : [];
            setTree(outline);
            onOutlineLoaded?.(outline);
        } catch (e) {
            setError(e.message || 'Không tải được outline.');
        } finally {
            setLoading(false);
        }
    }, [articleId, onOutlineLoaded]);

    useEffect(() => {
        void loadOutline();
    }, [loadOutline]);

    const exitDuplicateMode = useCallback(() => {
        setIsDuplicateModeActive(false);
        setDuplicates([]);
        setSelectedDuplicateArticleId(null);
        setActiveMatchedHeadingId(null);
        setCompareTree([]);
        setCompareArticleTitle('');
        setCompareError('');
    }, []);

    /** Toggle bật/tắt chế độ dò trùng lặp. */
    const handleToggleDuplicateMode = useCallback(async () => {
        if (isDuplicateModeActive) {
            exitDuplicateMode();
            return;
        }

        setIsDuplicateModeActive(true);
        setIsLoadingCheck(true);
        try {
            const data = await requestJson(checkDuplicatesUrl(articleId), {
                method: 'POST',
                body: JSON.stringify({}),
            });

            const found = Array.isArray(data.duplicates) ? data.duplicates : [];
            setDuplicates(found);
            setSelectedDuplicateArticleId(null);
            setActiveMatchedHeadingId(null);
            setCompareTree([]);
            setCompareArticleTitle('');
            setCompareError('');

            if (found.length > 0) {
                notify(
                    'Dò trùng lặp',
                    `Phát hiện ${found.length} heading trùng với bài viết khác trong site.`,
                    'warning',
                );
            } else {
                notify('Dò trùng lặp', 'Không phát hiện heading trùng lặp.', 'success');
            }
        } catch (e) {
            exitDuplicateMode();
            notify('Dò trùng lặp', e.message || 'Dò trùng lặp thất bại.', 'danger');
        } finally {
            setIsLoadingCheck(false);
        }
    }, [articleId, exitDuplicateMode, isDuplicateModeActive, notify]);

    /** Click cảnh báo đỏ ở cột trái -> nạp dàn ý bài bị trùng + highlight heading trùng. */
    const handleSelectDuplicate = useCallback((duplicateInfo) => {
        const id = Number(duplicateInfo?.matched_article_id);
        if (id <= 0) {
            return;
        }

        setSelectedDuplicateArticleId(id);
        const matchedHeadingId = Number(duplicateInfo?.matched_heading_id);
        setActiveMatchedHeadingId(matchedHeadingId > 0 ? matchedHeadingId : null);
    }, []);

    // Fetch outline (read-only) của bài bị trùng được chọn.
    useEffect(() => {
        if (!selectedDuplicateArticleId) {
            return;
        }

        let cancelled = false;
        setCompareLoading(true);
        setCompareError('');
        requestJson(outlineUrl(selectedDuplicateArticleId))
            .then((data) => {
                if (cancelled) return;
                setCompareTree(Array.isArray(data.outline) ? data.outline : []);
                setCompareArticleTitle(String(data.article?.title ?? ''));
            })
            .catch((e) => {
                if (cancelled) return;
                setCompareTree([]);
                setCompareArticleTitle('');
                setCompareError(e.message || 'Không tải được dàn ý bài viết gốc.');
            })
            .finally(() => {
                if (!cancelled) {
                    setCompareLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [selectedDuplicateArticleId]);

    // Scroll heading bị trùng (cột phải) vào vùng nhìn thấy sau khi tree render.
    useEffect(() => {
        if (!activeMatchedHeadingId || compareTree.length === 0) {
            return;
        }

        window.requestAnimationFrame(() => {
            const el = document.querySelector(
                `[data-readonly-heading-id="${activeMatchedHeadingId}"]`,
            );
            el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }, [activeMatchedHeadingId, compareTree]);

    useEffect(() => {
        if (!headingCommand?.token) {
            return;
        }

        const { level, headingText, action } = headingCommand;
        const match = findOutlineNodeWithGroup(tree, level, headingText);
        if (!match) {
            if (tree.length > 0) {
                notify('Outline', 'Không tìm thấy heading tương ứng trong outline.', 'warning');
            }
            return;
        }

        const { node, groupId } = match;
        setEditingHeadingId(null);
        setActiveGroupId(groupId);
        setActiveHeadingId(node.id);

        window.requestAnimationFrame(() => {
            const el = document.querySelector(`[data-outline-heading-id="${node.id}"]`);
            el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        if (action === 'edit') {
            setEditingHeadingId(node.id);
        }
    }, [headingCommand?.token, headingCommand?.level, headingCommand?.headingText, headingCommand?.action, notify, tree]);

    const handleEditingHeadingEnd = useCallback(() => {
        setEditingHeadingId(null);
    }, []);

    const applyHeadingPatch = useCallback(
        (node, newText) => {
            setTree((prev) => patchTreeNode(prev, node.id, { heading_text: newText }));
            onHeadingTextChange?.({
                level: node.level,
                oldText: node.heading_text,
                newText,
            });
        },
        [onHeadingTextChange],
    );

    const handleSaveText = useCallback(
        async (node, newText) => {
            // Optimistic update để UI mượt; lỗi thì revert.
            applyHeadingPatch(node, newText);
            try {
                const data = await requestJson(headingUrl(articleId, node.id), {
                    method: 'PUT',
                    body: JSON.stringify({ heading_text: newText }),
                });

                const duplicates = Array.isArray(data.duplicates) ? data.duplicates : [];
                if (duplicates.length > 0) {
                    notify(
                        'Cảnh báo trùng heading',
                        `Heading này trùng với ${duplicates.length} heading khác trong site (vd: "${duplicates[0].article_title}").`,
                        'warning',
                    );
                } else {
                    notify('Outline', 'Đã lưu heading.', 'success');
                }
            } catch (e) {
                applyHeadingPatch({ ...node, heading_text: newText }, node.heading_text);
                notify('Outline', e.message || 'Không lưu được heading.', 'danger');
            }
        },
        [applyHeadingPatch, articleId, notify],
    );

    const handleGenerate = useCallback(
        async (node) => {
            setBusyHeadingId(node.id);
            try {
                const data = await requestJson(generateUrl(articleId, node.id), {
                    method: 'POST',
                    body: JSON.stringify({}),
                });

                const newText = String(data.heading?.heading_text ?? '').trim();
                if (newText !== '' && newText !== node.heading_text) {
                    applyHeadingPatch(node, newText);
                }
                notify('Outline', 'Đã gen lại heading bằng AI.', 'success');
            } catch (e) {
                notify('Outline', e.message || 'AI gen heading thất bại.', 'danger');
            } finally {
                setBusyHeadingId(null);
            }
        },
        [applyHeadingPatch, articleId, notify],
    );

    return (
        <div
            className="seo-tab-panel seo-outline-panel"
            onClick={() => {
                setActiveGroupId(null);
                setActiveHeadingId(null);
                setEditingHeadingId(null);
            }}
        >
            <div className="seo-outline-toolbar">
                <h3 className="seo-outline-title">Outline / Dàn ý (H2–H4)</h3>
                <div className="seo-outline-toolbar__actions">
                    <button
                        type="button"
                        className="seo-outline-reload"
                        title="Tải lại outline từ nội dung bài"
                        disabled={loading}
                        onClick={(e) => {
                            e.stopPropagation();
                            void loadOutline();
                        }}
                    >
                        <RefreshCw
                            size={15}
                            strokeWidth={1.75}
                            className={loading ? 'seo-outline-spin' : ''}
                        />
                        Tải lại
                    </button>
                    <button
                        type="button"
                        className={[
                            'seo-outline-reload',
                            'seo-outline-check-btn',
                            isDuplicateModeActive ? 'is-active' : '',
                        ]
                            .filter(Boolean)
                            .join(' ')}
                        title={
                            isDuplicateModeActive
                                ? 'Thoát chế độ dò trùng lặp'
                                : 'Dò heading trùng lặp với các bài khác trong site'
                        }
                        disabled={
                            loading ||
                            isLoadingCheck ||
                            (tree.length === 0 && !isDuplicateModeActive)
                        }
                        onClick={(e) => {
                            e.stopPropagation();
                            void handleToggleDuplicateMode();
                        }}
                    >
                        {isLoadingCheck ? (
                            <Loader2 size={15} strokeWidth={1.75} className="seo-outline-spin" />
                        ) : (
                            <ShieldAlert size={15} strokeWidth={1.75} />
                        )}
                        {isLoadingCheck
                            ? 'Đang dò...'
                            : isDuplicateModeActive
                              ? 'Thoát chế độ dò trùng'
                              : 'Dò trùng lặp'}
                    </button>
                </div>
            </div>

            {loading ? (
                <div className="seo-outline-empty">
                    <Loader2 size={18} className="seo-outline-spin" /> Đang tải outline...
                </div>
            ) : error !== '' ? (
                <div className="seo-outline-empty seo-outline-empty--error">{error}</div>
            ) : tree.length === 0 ? (
                <div className="seo-outline-empty">
                    Bài viết chưa có heading H2–H4 nào để bóc tách.
                </div>
            ) : showDuplicateSplit ? (
                <div className="seo-outline-split">
                    <div className="seo-outline-split__col">
                        <div className="seo-outline-split__head seo-outline-split__head--current">
                            <AlertTriangle size={14} strokeWidth={1.75} />
                            Dàn ý hiện tại ({duplicates.length} heading trùng)
                        </div>
                        <div className="seo-outline-tree">
                            <OutlineTree
                                nodes={tree}
                                activeGroupId={activeGroupId}
                                activeHeadingId={activeHeadingId}
                                editingHeadingId={editingHeadingId}
                                onEditingHeadingEnd={handleEditingHeadingEnd}
                                onSelectGroup={handleSelectGroup}
                                onJumpToEditor={handleJumpToEditor}
                                onSaveText={handleSaveText}
                                onGenerate={handleGenerate}
                                busyHeadingId={busyHeadingId}
                                duplicateByHeadingId={duplicateByHeadingId}
                                onSelectDuplicate={handleSelectDuplicate}
                            />
                        </div>
                    </div>

                    <div
                        className="seo-outline-split__col seo-outline-split__col--readonly"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {!selectedDuplicateArticleId ? (
                            <div className="seo-outline-split__placeholder">
                                <ShieldAlert size={22} strokeWidth={1.5} />
                                Nhấn vào cảnh báo đỏ ở dàn ý bên trái để xem chi tiết bài viết bị
                                trùng.
                            </div>
                        ) : (
                            <>
                                <div className="seo-outline-split__head">
                                    Dàn ý bài viết gốc (ID: {selectedDuplicateArticleId})
                                </div>
                                {compareArticleTitle !== '' ? (
                                    <h3 className="seo-outline-split__article-title">
                                        Bài viết: {compareArticleTitle}
                                    </h3>
                                ) : null}
                                {compareLoading ? (
                                    <div className="seo-outline-empty">
                                        <Loader2 size={16} className="seo-outline-spin" /> Đang tải
                                        dàn ý bài gốc...
                                    </div>
                                ) : compareError !== '' ? (
                                    <div className="seo-outline-empty seo-outline-empty--error">
                                        {compareError}
                                    </div>
                                ) : compareTree.length === 0 ? (
                                    <div className="seo-outline-empty">Bài gốc chưa có dàn ý.</div>
                                ) : (
                                    <div className="seo-outline-tree">
                                        <ReadOnlyOutlineTree
                                            nodes={compareTree}
                                            activeHeadingId={activeMatchedHeadingId}
                                        />
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            ) : (
                <div className="seo-outline-tree">
                    <OutlineTree
                        nodes={tree}
                        activeGroupId={activeGroupId}
                        activeHeadingId={activeHeadingId}
                        editingHeadingId={editingHeadingId}
                        onEditingHeadingEnd={handleEditingHeadingEnd}
                        onSelectGroup={handleSelectGroup}
                        onJumpToEditor={handleJumpToEditor}
                        onSaveText={handleSaveText}
                        onGenerate={handleGenerate}
                        busyHeadingId={busyHeadingId}
                    />
                </div>
            )}
        </div>
    );
}
