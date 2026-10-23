/**
 * Top bar Editing Language menu (EditingLanguageToolbarItem): sets the Editing Language, then
 * reloads the page tree (titles, hidden state) and the module. An explicit "languages" selection
 * in the module URL is dropped, otherwise the Layout module would take it as the Editing Language.
 *
 * The Editing Language may also change in the Layout module; it then dispatches
 * "structure-layer:editing-language:changed" (RefreshOnEditingLanguageChange) and the item is re-rendered.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import RegularEvent from '@typo3/core/event/regular-event.js';
import Notification from '@typo3/backend/notification.js';
import Viewport from '@typo3/backend/viewport.js';

const reloadModule = () => {
  const contentWindow = Viewport.ContentContainer.get();
  if (!contentWindow?.location?.href || contentWindow.location.href === 'about:blank') {
    return;
  }
  const url = new URL(contentWindow.location.href);
  for (const key of [...url.searchParams.keys()]) {
    if (key.startsWith('languages')) {
      url.searchParams.delete(key);
    }
  }
  Viewport.ContentContainer.setUrl(url.pathname + url.search + url.hash);
};

const updateToolbarItem = (data) => {
  const toolbarItem = document.querySelector('.structure-layer-editing-language-toolbar');
  if (toolbarItem) {
    toolbarItem.querySelector('.toolbar-item-link').innerHTML = data.item;
    toolbarItem.querySelector('.dropdown-menu').innerHTML = data.dropDown;
  }
};

new RegularEvent('click', async (event, button) => {
  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.structure_layer_editing_language).post({
      site: button.dataset.site,
      language: button.dataset.languageId,
    });
    const data = await response.resolve();
    updateToolbarItem(data);
    if (data.changed) {
      top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
      reloadModule();
    }
  } catch {
    Notification.error('Editing language', 'The editing language could not be changed.');
  }
}).delegateTo(document, '.t3js-structure-layer-editing-language');

new RegularEvent('structure-layer:editing-language:changed', async () => {
  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.structure_layer_editing_language_menu).get();
    updateToolbarItem(await response.resolve());
  } catch {
    // The item shows the previous language until the next backend reload
  }
}).bindTo(document);
