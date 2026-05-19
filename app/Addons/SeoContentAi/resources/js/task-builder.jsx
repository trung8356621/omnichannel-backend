import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import ArticleFlowBuilder from './components/ArticleFlowBuilder';
import '../css/task-builder.css';

const rootElement = document.getElementById('seo-task-workflow-builder-root');

if (rootElement) {
    let initialFlowData = null;

    try {
        const flowJsonEl = document.getElementById('seo-task-initial-flow');
        const raw = flowJsonEl?.textContent?.trim();
        if (raw) {
            initialFlowData = JSON.parse(raw);
        }
    } catch (e) {
        console.warn('Invalid flow JSON', e);
    }

    const initialTaskName = rootElement.dataset.taskName || 'Quy trình SEO mới';

    const AppBridge = () => {
        const [taskName, setTaskName] = useState(initialTaskName);

        const handleSave = (name, flowJson) => {
            window.dispatchEvent(
                new CustomEvent('save-task-flow', {
                    detail: { name, flow_data: flowJson },
                }),
            );
        };

        return (
            <ArticleFlowBuilder
                initialData={initialFlowData}
                onSave={handleSave}
                taskName={taskName}
                setTaskName={setTaskName}
            />
        );
    };

    const root = createRoot(rootElement);
    root.render(<AppBridge />);
}
