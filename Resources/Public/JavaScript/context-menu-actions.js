/**
 * Page tree context menu actions of ShadowPageVisibilityItemProvider: enable / disable the
 * translation the tree shows, not the shadow (the item's uid).
 */
import Viewport from '@typo3/backend/viewport.js';

const getReturnUrl = () => encodeURIComponent(top.list_frame.document.location.pathname + top.list_frame.document.location.search);

const setDisabled = (table, dataset, value) => {
  const field = dataset.disableField || 'hidden';
  Viewport.ContentContainer.setUrl(
    top.TYPO3.settings.RecordCommit.moduleUrl
      + '&data[' + table + '][' + dataset.translationUid + '][' + field + ']=' + value
      + '&redirect=' + getReturnUrl()
  );
};

class ContextMenuActions {
  static enableTranslation(table, uid, dataset) {
    setDisabled(table, dataset, 0);
  }

  static disableTranslation(table, uid, dataset) {
    setDisabled(table, dataset, 1);
  }
}

export default ContextMenuActions;
