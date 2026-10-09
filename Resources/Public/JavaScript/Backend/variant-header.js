import Notification from '@typo3/backend/notification.js';
import DocumentService from '@typo3/core/document-service.js';
import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';
import { getVariantState } from '@andersundsehr/frontend-studio/backend/variant-state.js';

class VariantHeader extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.siteSelect = root.querySelector('[data-frontend-studio-site-select]');
    this.languageSelect = root.querySelector('[data-frontend-studio-language-select]');
    this.openRenderedVariantLink = root.querySelector('[data-frontend-studio-open-rendered-variant]');
    this.iframe = view.root.querySelector('[data-frontend-studio-variant-frame]');
    this.componentFilePath = root.dataset.componentFilePath || '';
    this.initializePreviewContextSelectors();
    this.listen(root.querySelector('[data-frontend-studio-copy-component-path]'), 'click', () => this.copyComponentFilePath());
  }

  initializePreviewContextSelectors() {
    if (this.siteSelect === null) {
      return;
    }

    this.siteSelect.value = this.root.dataset.selectedSiteIdentifier || this.siteSelect.value;
    if (this.languageSelect !== null) {
      this.languageSelect.value = this.root.dataset.selectedLanguageHreflang || this.languageSelect.value;
    }

    this.listen(this.siteSelect, 'change', () => {
      const languages = this.getSelectedSiteLanguages();
      const currentLanguage = this.languageSelect?.value || '';
      const language = languages.some((option) => option.value === currentLanguage)
        ? currentLanguage
        : (languages[0]?.value || '');

      this.updateLanguageOptions(languages, language);
      this.updatePreviewContext(this.siteSelect.value, language, languages);
    });

    this.listen(this.languageSelect, 'change', () => {
      this.updatePreviewContext(
        this.siteSelect.value,
        this.languageSelect.value,
        this.getSelectedSiteLanguages(),
      );
    });
  }

  getSelectedSiteLanguages() {
    try {
      return JSON.parse(this.siteSelect.selectedOptions[0]?.dataset.languages || '[]');
    } catch {
      return [];
    }
  }

  updateLanguageOptions(languages, selectedLanguage) {
    if (this.languageSelect === null) {
      return;
    }

    this.languageSelect.replaceChildren(...languages.map((language) => new Option(language.title, language.value)));
    this.languageSelect.disabled = languages.length === 0;
    this.languageSelect.value = selectedLanguage;
  }

  updatePreviewContext(siteIdentifier, languageHreflang, languages) {
    const language = languages.find((option) => option.value === languageHreflang);
    const contexts = window === top ? [window] : [window, top];
    contexts.forEach((context) => {
      const moduleUrl = new URL(context.location.href);
      moduleUrl.searchParams.set('site', siteIdentifier);
      if (languageHreflang !== '') {
        moduleUrl.searchParams.set('language', languageHreflang);
      } else {
        moduleUrl.searchParams.delete('language');
      }
      context.history.replaceState(context.history.state, '', moduleUrl.toString());
    });

    this.root.dataset.selectedSiteIdentifier = siteIdentifier;
    this.root.dataset.selectedLanguageHreflang = languageHreflang;
    top.document.dispatchEvent(new CustomEvent('frontend-studio:preview-context-changed'));
    if (language === undefined) {
      this.view.previewUri = '';
      if (this.iframe !== null) {
        this.iframe.src = 'about:blank';
      }
      if (this.openRenderedVariantLink !== null) {
        this.openRenderedVariantLink.hidden = true;
      }
      this.view.changed('context');
      return;
    }

    const previewUrl = new URL('/__frontendStudio/preview', window.location.href);
    previewUrl.searchParams.set('componentVariant', this.view.variantIdentifier);
    previewUrl.searchParams.set('site', siteIdentifier);
    previewUrl.searchParams.set('language', languageHreflang);
    this.view.previewUri = previewUrl.toString();

    if (this.openRenderedVariantLink !== null) {
      this.openRenderedVariantLink.hidden = false;
      this.openRenderedVariantLink.href = this.view.previewUri;
    }

    this.view.changed('context');
  }

  async copyComponentFilePath() {
    if (this.componentFilePath === '') {
      return;
    }

    try {
      await navigator.clipboard.writeText(this.componentFilePath);
      if (!this.destroyed) {
        Notification.success('File path copied', this.componentFilePath);
      }
    } catch (error) {
      if (!this.destroyed) {
        Notification.error('Copy failed', error?.message || 'The component file path could not be copied.');
      }
    }
  }

  static initialize() {
    document.querySelectorAll('[data-frontend-studio-variant-header]').forEach((root) => {
      const view = getVariantState(root);
      view.mount(root, () => new VariantHeader(root, view));
    });
  }
}

export default VariantHeader;

DocumentService.ready().then(() => VariantHeader.initialize());
