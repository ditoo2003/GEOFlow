export const initializeAiModelTypeFields = (form) => {
    if (!form) return () => {};

    const modelType = form.querySelector('[name="model_type"]');
    const maxTokensField = form.querySelector('[data-max-tokens-field]');
    const maxTokens = form.querySelector('[name="max_tokens"]');
    const supportsMaxTokens = form.dataset.supportsMaxTokens === 'true';

    const sync = () => {
        if (!maxTokensField || !maxTokens) return;

        const visible = supportsMaxTokens && modelType?.value === 'chat';
        maxTokensField.classList.toggle('hidden', !visible);
        maxTokens.disabled = !visible;

        if (!visible) maxTokens.value = '';
    };

    modelType?.addEventListener('change', sync);
    sync();

    return sync;
};

export const initializeAiModelCapabilityFields = (form) => {
    if (!form) return () => {};

    const modelType = form.querySelector('[name="model_type"]');
    const provider = form.querySelector('[name="provider_key"]');
    const protocol = form.querySelector('[name="protocol"]');
    const hint = form.querySelector('[data-capability-protocol-hint]');
    const boxes = Array.from(form.querySelectorAll('[data-capability-option]'));

    if (!modelType || boxes.length === 0) return () => {};

    let syncing = false;

    const selected = () => new Set(boxes.filter((box) => box.checked).map((box) => box.value));

    const syncDisabled = () => {
        const values = selected();
        boxes.forEach((box) => {
            if (box.value === 'chat') {
                box.disabled = values.has('embedding') || values.has('multimodal_embedding');
            }
            if (box.value === 'embedding' || box.value === 'multimodal_embedding') {
                box.disabled = values.has('chat');
            }
        });
    };

    const protocolLabel = (value) => {
        const option = Array.from(protocol?.options || []).find((item) => item.value === value);

        return option?.textContent.trim() || value;
    };

    const syncHint = () => {
        if (!hint) return;

        if (protocol?.value) {
            hint.textContent = '';
            hint.classList.add('hidden');

            return;
        }

        hint.classList.remove('hidden');
        const values = selected();
        let recommended = provider?.selectedOptions?.[0]?.dataset.providerProtocol || 'openai_chat';

        if (values.has('multimodal_embedding')) {
            recommended = 'openai_multimodal_embedding';
        } else if (values.has('embedding') || modelType.value === 'embedding') {
            recommended = 'openai_embedding';
        }

        hint.textContent = (form.dataset.protocolAutoHint || '')
            .replace(':protocol', protocolLabel(recommended));
    };

    const syncFromCapabilities = () => {
        if (syncing) return;

        syncing = true;
        const values = selected();
        let nextType = null;

        if (values.has('multimodal_embedding') || values.has('embedding')) {
            nextType = 'embedding';
        } else if (values.has('chat')) {
            nextType = 'chat';
        }

        if (nextType !== null && modelType.value !== nextType) {
            modelType.value = nextType;
            modelType.dispatchEvent(new Event('change', { bubbles: true }));
        }

        syncDisabled();
        syncHint();
        syncing = false;
    };

    const syncFromModelType = () => {
        if (syncing) return;

        syncing = true;
        boxes.forEach((box) => {
            if (modelType.value === 'chat' && (box.value === 'embedding' || box.value === 'multimodal_embedding')) {
                box.checked = false;
            }
            if (modelType.value === 'embedding' && box.value === 'chat') {
                box.checked = false;
            }
        });

        syncDisabled();
        syncHint();
        syncing = false;
    };

    boxes.forEach((box) => box.addEventListener('change', syncFromCapabilities));
    modelType.addEventListener('change', syncFromModelType);
    provider?.addEventListener('change', syncHint);
    protocol?.addEventListener('change', syncHint);

    syncDisabled();
    syncHint();

    return () => {
        syncDisabled();
        syncHint();
    };
};
