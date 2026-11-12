import { initializeAiModelCapabilityFields, initializeAiModelTypeFields } from './ai-model-form';

initializeAiModelTypeFields(document.querySelector('[data-ai-model-edit-form]'));
initializeAiModelCapabilityFields(document.querySelector('[data-ai-model-edit-form]'));
