/**
 * Context menu actions of the structure layer:
 * - ShadowPageVisibilityItemProvider: enable / disable the translation the page tree shows, not
 *   the shadow (the item's uid);
 * - AllLanguagesVisibilityItemProvider: hide / show a translation in all languages, sent as
 *   "hidden" for its shadow.
 */
import Viewport from '@typo3/backend/viewport.js';

const getReturnUrl = () => encodeURIComponent(top.list_frame.document.location.pathname + top.list_frame.document.location.search);

const setDisabled = (table, uid, field, value) => {
  Viewport.ContentContainer.setUrl(
    top.TYPO3.settings.RecordCommit.moduleUrl
      + '&data[' + table + '][' + uid + '][' + (field || 'hidden') + ']=' + value
      + '&redirect=' + getReturnUrl()
  );
};

class ContextMenuActions {
  static enableTranslation(table, uid, dataset) {
    setDisabled(table, dataset.translationUid, dataset.disableField, 0);
  }

  static disableTranslation(table, uid, dataset) {
    setDisabled(table, dataset.translationUid, dataset.disableField, 1);
  }

  static setHiddenInAllLanguages(table, uid, dataset) {
    setDisabled(table, dataset.shadowUid, dataset.disableField, dataset.hidden === '1' ? 1 : 0);
  }
}

export default ContextMenuActions;
