/**
 * Snelstack Editor Sidebar — Translations.
 *
 * One post per language, linked by a translation group. This panel shows the
 * current page's language, links to existing translations, and creates a new
 * translation (duplicate + AI-translate) in a selectable target language.
 */
import './muted-format';  // registers the snel/muted inline format (toolbar toggle)
import './accent-format'; // registers the snel/accent inline format (toolbar toggle)
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import { useState, useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { PanelBody, SelectControl, Button, ExternalLink, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

// ─── Data ─────────────────────────────────────────────────────────────────

function getData() {
	return window.snelCreateTranslation || null;
}

// ─── Badge ──────────────────────────────────────────────────────────────────

const BADGE_COLORS = {
	amber: { bg: '#fef3e2', fg: '#b45309' },
	gray:  { bg: '#eef1f4', fg: '#556270' },
	green: { bg: '#e7f6ec', fg: '#15803d' },
	blue:  { bg: '#e8f0fe', fg: '#1d4ed8' },
};

function Badge({ color = 'gray', children }) {
	const c = BADGE_COLORS[color] || BADGE_COLORS.gray;
	return (
		<span
			style={{
				display: 'inline-flex',
				alignItems: 'center',
				gap: 4,
				padding: '1px 7px',
				borderRadius: 999,
				fontSize: 9,
				fontWeight: 700,
				letterSpacing: '0.03em',
				textTransform: 'uppercase',
				lineHeight: 1.7,
				background: c.bg,
				color: c.fg,
				whiteSpace: 'nowrap',
			}}
		>
			<span style={{ width: 5, height: 5, borderRadius: 999, background: c.fg, opacity: 0.85 }} />
			{children}
		</span>
	);
}

// Status/outdated badges for a language entry, reused in the header + list.
function LangBadges({ lang, outdated, isSource }) {
	return (
		<>
			{isSource && <Badge color="blue">{__('source', 'snel')}</Badge>}
			{outdated && <Badge color="amber">{__('needs update', 'snel')}</Badge>}
			{lang.status && lang.status !== 'publish' && (
				<Badge color="gray">{lang.status}</Badge>
			)}
		</>
	);
}

// ─── Translations Panel ─────────────────────────────────────────────────────

function TranslationsPanel() {
	const data = getData();
	const { savePost } = useDispatch('core/editor');

	// Live copy of the per-language state; refreshed from the server after saves
	// and after create/sync so badges update without a full page reload.
	const [languages, setLanguages] = useState(data?.languages || []);
	const current = languages.find((l) => l.isCurrent);
	const others = languages.filter((l) => !l.isCurrent);
	const missing = others.filter((l) => !l.postId);
	const isDefault = data?.currentLang === data?.defaultLang;
	const source = languages.find((l) => l.code === data?.defaultLang);

	// Default the dropdown to the first language without a translation yet.
	const [target, setTarget] = useState((missing[0] || others[0])?.code || '');
	const [busy, setBusy] = useState(false);
	const [status, setStatus] = useState('');
	const [result, setResult] = useState(null);
	const [batch, setBatch] = useState(null); // per-language progress for "create all" / "sync all"
	const [showSyncAll, setShowSyncAll] = useState(false);

	// Existing translations that are out of date (source edited since).
	const isOutdated = (l) => l.postId && l.outdated;
	const outdated = others.filter(isOutdated);
	const isSourceLang = (l) => l.code === data?.defaultLang;

	// Re-fetch the translation state from the server (statuses, outdated flags).
	const refreshState = async () => {
		const postId = data?.postId || window.wp?.data?.select('core/editor')?.getCurrentPostId();
		if (!postId) return;
		try {
			const body = new URLSearchParams({ action: 'snel_translation_state', nonce: data.nonce, post_id: postId });
			const res = await fetch(data.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
			const json = await res.json();
			if (json.success && json.data && Array.isArray(json.data.languages)) {
				setLanguages(json.data.languages);
			}
		} catch (e) { /* ignore */ }
	};

	// Refresh whenever a (non-autosave) save finishes — the source signature
	// only changes on save, so that's when outdated flags can flip.
	const isSaving = useSelect((select) => {
		const ed = select('core/editor');
		return ed.isSavingPost() && !ed.isAutosavingPost();
	}, []);
	const wasSaving = useRef(false);
	useEffect(() => {
		if (wasSaving.current && !isSaving) {
			refreshState();
		}
		wasSaving.current = isSaving;
	}, [isSaving]);

	if (!data || !data.postId) {
		return (
			<p style={{ color: '#999', fontSize: 13 }}>
				{__('Save this page first to manage its translations.', 'snel')}
			</p>
		);
	}

	const targetLang = others.find((l) => l.code === target);

	// Run one create/sync request. Returns { ok, editUrl, postId, existed, msg }.
	const callAction = async (action, postId, lang) => {
		try {
			const body = new URLSearchParams({
				action,
				nonce: data.nonce,
				post_id: postId,
				target: lang,
			});
			const res = await fetch(data.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
			const raw = await res.text();
			let json;
			try { json = JSON.parse(raw); } catch (e) {
				return { ok: false, msg: __('Unexpected response. HTTP', 'snel') + ' ' + res.status };
			}
			if (json.success && json.data && json.data.edit_url) {
				return { ok: true, editUrl: json.data.edit_url, postId: json.data.post_id, existed: json.data.existed };
			}
			return { ok: false, msg: (json.data && (json.data.message || json.data)) || 'unknown' };
		} catch (err) {
			return { ok: false, msg: err.message };
		}
	};

	const currentPostId = () =>
		data.postId || window.wp?.data?.select('core/editor')?.getCurrentPostId();

	const handleCreate = async () => {
		if (!targetLang) return;
		setBusy(true);
		setResult(null);
		setBatch(null);
		setStatus(__('Saving page…', 'snel'));

		const postId = currentPostId();
		try { await savePost(); } catch (e) { /* continue with last saved content */ }

		setStatus(__('Translating… this can take a moment.', 'snel'));
		const r = await callAction('snel_create_translation', postId, target);
		if (r.ok) {
			setResult({ edit_url: r.editUrl, post_id: r.postId });
			setStatus(r.existed ? __('Already exists:', 'snel') : __('Created!', 'snel'));
		} else {
			setStatus(__('Error:', 'snel') + ' ' + r.msg);
		}
		await refreshState();
		setBusy(false);
	};

	const handleCreateAll = async () => {
		if (!missing.length) return;
		setBusy(true);
		setResult(null);
		setBatch(missing.map((l) => ({ code: l.code, label: l.label, state: 'pending' })));
		setStatus(__('Saving page…', 'snel'));

		const postId = currentPostId();
		try { await savePost(); } catch (e) { /* continue with last saved content */ }

		for (let i = 0; i < missing.length; i++) {
			const l = missing[i];
			setStatus(`${__('Translating', 'snel')} ${l.label} (${i + 1}/${missing.length})…`);
			setBatch((prev) => prev.map((it) => (it.code === l.code ? { ...it, state: 'working' } : it)));
			const r = await callAction('snel_create_translation', postId, l.code);
			setBatch((prev) => prev.map((it) => (
				it.code === l.code ? { ...it, state: r.ok ? 'done' : 'error', editUrl: r.editUrl, msg: r.msg } : it
			)));
		}
		await refreshState();
		setStatus(__('Done.', 'snel'));
		setBusy(false);
	};

	const handleSyncOne = async (lang) => {
		setBusy(true);
		setResult(null);
		setBatch(null);
		setStatus(`${__('Re-translating', 'snel')} ${lang}…`);

		const postId = currentPostId();
		const r = await callAction('snel_sync_translation', postId, lang);
		if (r.ok) {
			setResult({ edit_url: r.editUrl, post_id: r.postId });
			setStatus(__('Synced!', 'snel'));
		} else {
			setStatus(__('Error:', 'snel') + ' ' + r.msg);
		}
		await refreshState();
		setBusy(false);
	};

	const handleSyncAll = async () => {
		if (!outdated.length) return;
		setBusy(true);
		setResult(null);
		setBatch(outdated.map((l) => ({ code: l.code, label: l.label, state: 'pending' })));
		setStatus(__('Syncing outdated translations…', 'snel'));

		const postId = currentPostId();
		for (let i = 0; i < outdated.length; i++) {
			const l = outdated[i];
			setStatus(`${__('Re-translating', 'snel')} ${l.label} (${i + 1}/${outdated.length})…`);
			setBatch((prev) => prev.map((it) => (it.code === l.code ? { ...it, state: 'working' } : it)));
			const r = await callAction('snel_sync_translation', postId, l.code);
			setBatch((prev) => prev.map((it) => (
				it.code === l.code ? { ...it, state: r.ok ? 'done' : 'error', editUrl: r.editUrl, msg: r.msg } : it
			)));
		}
		await refreshState();
		setStatus(__('Done.', 'snel'));
		setBusy(false);
	};

	return (
		<div>
			{/* Current language */}
			<div style={{ position: 'relative', marginBottom: 16, padding: '10px 12px', background: '#f6f7f9', borderRadius: 8, fontSize: 13 }}>
				<span style={{ fontSize: 10, fontWeight: 700, color: '#9aa4b0', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
					{__('This page', 'snel')}
				</span>
				<div style={{ marginTop: 3, color: '#1e2733', fontWeight: 700 }}>
					{current ? current.label : '—'}
				</div>
				{current && (
					<div style={{ position: 'absolute', top: 8, right: 8, display: 'flex', gap: 4 }}>
						<LangBadges lang={current} outdated={isOutdated(current)} isSource={isSourceLang(current)} />
					</div>
				)}
			</div>

			{/* Existing translations */}
			{others.some((l) => l.postId) && (
				<div style={{ marginBottom: 16 }}>
					<span style={{ fontSize: 10, fontWeight: 700, color: '#9aa4b0', textTransform: 'uppercase', letterSpacing: '0.04em', display: 'block', marginBottom: 8 }}>
						{__('Translations', 'snel')}
					</span>
					{others.filter((l) => l.postId).map((l) => (
						<div key={l.code} style={{ padding: '8px 10px', marginBottom: 6, borderRadius: 8, border: '1px solid #eef0f2' }}>
							{(isSourceLang(l) || isOutdated(l) || (l.status && l.status !== 'publish')) && (
								<div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginBottom: 10 }}>
									<LangBadges lang={l} outdated={isOutdated(l)} isSource={isSourceLang(l)} />
								</div>
							)}
							<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
								<strong style={{ fontWeight: 700 }}>{l.label}</strong>
								<span style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
									<a href={l.editUrl}>{__('Edit', 'snel')}</a>
									{l.viewUrl && <> · <ExternalLink href={l.viewUrl}>{__('View', 'snel')}</ExternalLink></>}
									{isOutdated(l) && (
										<> · <a
											href="#"
											onClick={(e) => {
												e.preventDefault();
												if (!busy && window.confirm(__('Re-translate and overwrite this translation? Manual edits will be lost.', 'snel'))) {
													handleSyncOne(l.code);
												}
											}}
										>{__('Sync', 'snel')}</a></>
									)}
								</span>
							</div>
						</div>
					))}
				</div>
			)}

			{/* Create a new translation — only from the default (source) language */}
			{!isDefault ? (
				<p style={{ fontSize: 13, color: '#666' }}>
					{__('Translations are created from the source language.', 'snel')}
					{source && source.editUrl && (
						<> <a href={source.editUrl}>{__('Open the', 'snel')} {source.label} {__('source →', 'snel')}</a></>
					)}
				</p>
			) : others.length > 0 ? (
				<>
					<SelectControl
						label={__('Target language', 'snel')}
						value={target}
						options={others.map((l) => ({
							label: l.postId ? `${l.label} ✓` : l.label,
							value: l.code,
						}))}
						onChange={setTarget}
						__nextHasNoMarginBottom
					/>

					{targetLang && targetLang.postId ? (
						<p style={{ fontSize: 12, color: '#666', marginTop: 8 }}>
							{__('This language already exists — edit it above.', 'snel')}
						</p>
					) : (
						<Button
							variant="primary"
							onClick={handleCreate}
							isBusy={busy}
							disabled={busy || !targetLang}
							style={{ width: '100%', justifyContent: 'center', marginTop: 8 }}
						>
							{busy
								? __('Working…', 'snel')
								: `✦ ${__('Create', 'snel')} ${targetLang ? targetLang.label : ''}`}
						</Button>
					)}

					{missing.length > 0 && (
						<Button
							variant="secondary"
							onClick={handleCreateAll}
							isBusy={busy}
							disabled={busy}
							style={{ width: '100%', justifyContent: 'center', marginTop: 8 }}
						>
							{`✦ ${__('Create all missing', 'snel')} (${missing.length})`}
						</Button>
					)}
				</>
			) : (
				<p style={{ color: '#999', fontSize: 13 }}>
					{__('Only one language is configured.', 'snel')}
				</p>
			)}

			{outdated.length > 0 && (
				<Button
					variant="secondary"
					isDestructive
					onClick={() => setShowSyncAll(true)}
					disabled={busy}
					style={{ width: '100%', justifyContent: 'center', marginTop: 8 }}
				>
					{`↻ ${__('Sync all outdated', 'snel')} (${outdated.length})`}
				</Button>
			)}

			{status && <p style={{ marginTop: 8, fontSize: 12, color: '#666' }}>{status}</p>}

			{batch && (
				<div style={{ marginTop: 8 }}>
					{batch.map((it) => (
						<div key={it.code} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 0', fontSize: 12, borderBottom: '1px solid #f0f0f0' }}>
							<span style={{ fontWeight: 600 }}>{it.label}</span>
							<span>
								{it.state === 'pending' && <span style={{ color: '#999' }}>{__('waiting…', 'snel')}</span>}
								{it.state === 'working' && <span style={{ color: '#2271b1' }}>{__('translating…', 'snel')}</span>}
								{it.state === 'done' && (it.editUrl
									? <a href={it.editUrl}>{__('open →', 'snel')}</a>
									: <span style={{ color: '#16a34a' }}>✓</span>)}
								{it.state === 'error' && <span style={{ color: '#d63638' }}>{__('failed', 'snel')}{it.msg ? ': ' + it.msg : ''}</span>}
							</span>
						</div>
					))}
				</div>
			)}

			{result && result.edit_url && (
				<div style={{ marginTop: 8, padding: '8px 12px', background: '#edfaef', border: '1px solid #b6e0bf', borderRadius: 6 }}>
					<a href={result.edit_url} style={{ fontWeight: 600 }}>
						{__('Open translation →', 'snel')}
					</a>
					<div style={{ fontSize: 11, color: '#666', marginTop: 2 }}>
						{__('Post ID', 'snel')}: {result.post_id}
					</div>
				</div>
			)}
			{showSyncAll && (
				<Modal title={__('Sync outdated translations', 'snel')} onRequestClose={() => setShowSyncAll(false)}>
					<p style={{ fontSize: 13, marginTop: 0 }}>
						{__('This re-translates the source content into each outdated language and overwrites the current translated content. Any manual edits in those translations will be lost.', 'snel')}
					</p>
					<p style={{ fontSize: 13, fontWeight: 600 }}>
						{outdated.length} {__('will be overwritten:', 'snel')} {outdated.map((l) => l.label).join(', ')}
					</p>
					<div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
						<Button variant="tertiary" onClick={() => setShowSyncAll(false)}>{__('Cancel', 'snel')}</Button>
						<Button variant="primary" isDestructive onClick={() => { setShowSyncAll(false); handleSyncAll(); }}>
							{__('Sync all', 'snel')}
						</Button>
					</div>
				</Modal>
			)}
		</div>
	);
}

// ─── Sidebar ────────────────────────────────────────────────────────────────

function SidebarContent() {
	return (
		<PanelBody title={__('Translations', 'snel')} initialOpen icon="translation">
			<TranslationsPanel />
		</PanelBody>
	);
}

// ─── Icon & Plugin Registration ─────────────────────────────────────────────

const SnelIcon = () => (
	<span className="snel-editor-icon" style={{ display: 'inline-block', width: 20, height: 20, borderRadius: '50%', position: 'relative', overflow: 'hidden', background: 'linear-gradient(135deg, #3b82f6, #7c3aed)' }}>
		<span className="snel-gradient-ring" style={{ display: 'none', position: 'absolute', top: '50%', left: '50%', width: 30, height: 30, background: 'conic-gradient(from 0deg, #06b6d4, #3b82f6, #8b5cf6, #d946ef, #f43f5e, #f97316, #eab308, #22c55e, #06b6d4)', animation: 'snel-editor-gradient-spin 3s linear infinite', zIndex: 1 }} />
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={{ position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%)', width: 14, height: 14, zIndex: 2 }}>
			<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z" fill="#fff" />
		</svg>
	</span>
);

registerPlugin('snel-editor-sidebar', {
	render: () => (
		<>
			<style>{`
				@keyframes snel-editor-gradient-spin {
					0%   { transform: translate(-50%, -50%) rotate(0deg); }
					100% { transform: translate(-50%, -50%) rotate(360deg); }
				}
				button.components-button[aria-label="Snel Stack"].is-pressed .snel-editor-icon,
				button.components-button[aria-label="Snel Stack"][aria-pressed="true"] .snel-editor-icon,
				button.components-button[aria-label="Snel Stack"][aria-expanded="true"] .snel-editor-icon {
					overflow: hidden;
				}
				button.components-button[aria-label="Snel Stack"].is-pressed .snel-editor-icon .snel-gradient-ring,
				button.components-button[aria-label="Snel Stack"][aria-pressed="true"] .snel-editor-icon .snel-gradient-ring,
				button.components-button[aria-label="Snel Stack"][aria-expanded="true"] .snel-editor-icon .snel-gradient-ring {
					display: block !important;
				}
			`}</style>
			<PluginSidebar
				name="snel-editor-sidebar"
				title={__('Snel Stack', 'snel')}
				icon={<SnelIcon />}
				isPinnable={true}
			>
				<SidebarContent />
			</PluginSidebar>
		</>
	),
});
