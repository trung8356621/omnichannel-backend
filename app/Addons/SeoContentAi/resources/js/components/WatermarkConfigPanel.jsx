import React, { useEffect, useState } from 'react';
import {
    WATERMARK_POSITIONS,
    applyWatermarkBatch,
    fetchWatermarkSettings,
    saveWatermarkSettings,
} from '../utils/watermarkApi';

export default function WatermarkConfigPanel({ sites = [], defaultSiteId = null, onBatchDone }) {
    const [selectedSiteIds, setSelectedSiteIds] = useState(
        defaultSiteId ? [Number(defaultSiteId)] : [],
    );
    const [configSiteId, setConfigSiteId] = useState(defaultSiteId ? Number(defaultSiteId) : null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [batching, setBatching] = useState(false);
    const [message, setMessage] = useState(null);

    const [type, setType] = useState('none');
    const [textContent, setTextContent] = useState('Bản quyền hình ảnh');
    const [textColor, setTextColor] = useState('#ffffff');
    const [textSize, setTextSize] = useState(20);
    const [logoWidthPct, setLogoWidthPct] = useState(20);
    const [position, setPosition] = useState('bottom-right');
    const [opacity, setOpacity] = useState(0.7);
    const [logoFile, setLogoFile] = useState(null);
    const [logoPreview, setLogoPreview] = useState(null);

    useEffect(() => {
        if (!configSiteId) return;

        setLoading(true);
        setMessage(null);
        fetchWatermarkSettings(configSiteId)
            .then((settings) => {
                setType(settings.type ?? 'none');
                setTextContent(settings.text_content ?? 'Bản quyền hình ảnh');
                setTextColor(settings.text_color ?? '#ffffff');
                setTextSize(settings.text_size ?? 20);
                setLogoWidthPct(settings.logo_width_pct ?? 20);
                setPosition(settings.position ?? 'bottom-right');
                setOpacity(settings.opacity ?? 0.7);
                setLogoPreview(settings.logo_url ?? null);
            })
            .catch((err) => setMessage({ type: 'error', text: err.message }))
            .finally(() => setLoading(false));
    }, [configSiteId]);

    const toggleSite = (id) => {
        setSelectedSiteIds((prev) =>
            prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id],
        );
    };

    const handleSaveSettings = async () => {
        if (!configSiteId) return;

        setSaving(true);
        setMessage(null);
        try {
            const payload = {
                type,
                text_content: textContent,
                text_color: textColor,
                text_size: textSize,
                logo_width_pct: logoWidthPct,
                position,
                opacity,
            };
            const result = await saveWatermarkSettings(configSiteId, payload, logoFile);
            setMessage({ type: 'success', text: result.message ?? 'Đã lưu cấu hình.' });
            if (result.settings?.logo_url) {
                setLogoPreview(result.settings.logo_url);
            }
            setLogoFile(null);
        } catch (err) {
            setMessage({ type: 'error', text: err.message });
        } finally {
            setSaving(false);
        }
    };

    const handleBatch = async () => {
        if (!selectedSiteIds.length) return;

        if (
            !window.confirm(
                'Đóng dấu hàng loạt sẽ ghi đè file ảnh nội bộ (tab Nội bộ) trên đĩa. Tiếp tục?',
            )
        ) {
            return;
        }

        setBatching(true);
        setMessage(null);
        try {
            const result = await applyWatermarkBatch(selectedSiteIds);
            setMessage({ type: 'success', text: result.message ?? 'Hoàn tất.' });
            onBatchDone?.();
        } catch (err) {
            setMessage({ type: 'error', text: err.message });
        } finally {
            setBatching(false);
        }
    };

    return (
        <div className="seo-watermark-config">
            <header className="seo-watermark-config__header">
                <h2>Cấu hình đóng dấu bản quyền</h2>
                <p>
                    Cấu hình mặc định theo từng website. Đóng dấu hàng loạt chỉ áp dụng cho ảnh{' '}
                    <strong>Nội bộ (Laravel)</strong> trong tab thư viện.
                </p>
            </header>

            {message ? (
                <div className={`seo-watermark-config__alert is-${message.type}`}>{message.text}</div>
            ) : null}

            <div className="seo-watermark-config__grid">
                <section className="seo-watermark-config__panel">
                    <h3>Website áp dụng hàng loạt</h3>
                    <ul className="seo-watermark-config__site-list">
                        {sites.map((site) => (
                            <li key={site.id}>
                                <label>
                                    <input
                                        type="checkbox"
                                        checked={selectedSiteIds.includes(site.id)}
                                        onChange={() => toggleSite(site.id)}
                                    />
                                    {site.domain}
                                </label>
                            </li>
                        ))}
                    </ul>
                    <button
                        type="button"
                        className="seo-watermark-config__btn is-primary"
                        disabled={batching || !selectedSiteIds.length || type === 'none'}
                        onClick={handleBatch}
                    >
                        {batching ? 'Đang đóng dấu…' : 'Áp dụng cho toàn bộ ảnh nội bộ'}
                    </button>
                </section>

                <section className="seo-watermark-config__panel">
                    <h3>Cấu hình mặc định</h3>
                    <label className="seo-watermark-config__label">Website cấu hình</label>
                    <select
                        value={configSiteId ?? ''}
                        onChange={(e) => setConfigSiteId(e.target.value ? Number(e.target.value) : null)}
                        className="seo-watermark-config__select"
                    >
                        <option value="">— Chọn website —</option>
                        {sites.map((site) => (
                            <option key={site.id} value={site.id}>
                                {site.domain}
                            </option>
                        ))}
                    </select>

                    {loading ? <p>Đang tải cấu hình…</p> : null}

                    {configSiteId ? (
                        <div className="seo-watermark-config__form">
                            <label className="seo-watermark-config__label">Loại</label>
                            <select value={type} onChange={(e) => setType(e.target.value)} className="seo-watermark-config__select">
                                <option value="none">Không đóng dấu</option>
                                <option value="text">Chữ (text)</option>
                                <option value="image">Logo (ảnh)</option>
                            </select>

                            {type === 'text' ? (
                                <>
                                    <label className="seo-watermark-config__label">Nội dung chữ</label>
                                    <input
                                        type="text"
                                        value={textContent}
                                        onChange={(e) => setTextContent(e.target.value)}
                                        className="seo-watermark-config__input"
                                    />
                                    <div className="seo-watermark-config__row">
                                        <input
                                            type="color"
                                            value={textColor}
                                            onChange={(e) => setTextColor(e.target.value)}
                                        />
                                        <input
                                            type="number"
                                            min={8}
                                            max={120}
                                            value={textSize}
                                            onChange={(e) => setTextSize(parseInt(e.target.value, 10) || 12)}
                                            className="seo-watermark-config__input"
                                        />
                                    </div>
                                </>
                            ) : null}

                            {type === 'image' ? (
                                <>
                                    <label className="seo-watermark-config__label">Logo watermark</label>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => setLogoFile(e.target.files?.[0] ?? null)}
                                    />
                                    {logoPreview ? (
                                        <img src={logoPreview} alt="" className="seo-watermark-config__logo" />
                                    ) : null}
                                    <label className="seo-watermark-config__label">
                                        Chiều rộng logo (% ảnh): {logoWidthPct}%
                                    </label>
                                    <input
                                        type="range"
                                        min={5}
                                        max={80}
                                        value={logoWidthPct}
                                        onChange={(e) => setLogoWidthPct(parseInt(e.target.value, 10))}
                                    />
                                </>
                            ) : null}

                            {type !== 'none' ? (
                                <>
                                    <label className="seo-watermark-config__label">Vị trí</label>
                                    <select
                                        value={position}
                                        onChange={(e) => setPosition(e.target.value)}
                                        className="seo-watermark-config__select"
                                    >
                                        {WATERMARK_POSITIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                    <label className="seo-watermark-config__label">Độ mờ: {opacity}</label>
                                    <input
                                        type="range"
                                        min={0.1}
                                        max={1}
                                        step={0.05}
                                        value={opacity}
                                        onChange={(e) => setOpacity(parseFloat(e.target.value))}
                                    />
                                </>
                            ) : null}

                            <button
                                type="button"
                                className="seo-watermark-config__btn is-primary"
                                disabled={saving}
                                onClick={handleSaveSettings}
                            >
                                {saving ? 'Đang lưu…' : 'Lưu cấu hình'}
                            </button>
                        </div>
                    ) : null}
                </section>
            </div>
        </div>
    );
}
