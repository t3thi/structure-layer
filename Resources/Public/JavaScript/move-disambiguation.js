/**
 * Move disambiguation in the Layout module (plan §5, Phase 5, Option B of 2026-05-29).
 *
 * A move always moves an element in all languages. In one language's column, shadows of other
 * languages are invisible: a drop after a visible element is ambiguous if such shadows follow it.
 * Then this module stops Core's drop handler (drag-drop.js listens on document in the capture
 * phase; this listener runs earlier, on window) and asks where in the shared structure the element
 * goes. Unambiguous drops are left to Core.
 *
 * The positions come from AddMoveDisambiguation: language => colPos => anchor uid => hidden shadows.
 */
import AjaxDataHandler from '@typo3/backend/ajax-data-handler.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import { DataTransferTypes } from '@typo3/backend/enum/data-transfer-types.js';

const label = (key, fallback) => (window.TYPO3?.lang?.['moveDisambiguation.' + key] || fallback);

const readPositions = () => {
  const element = document.getElementById('structure-layer-hidden-positions');
  return element === null ? {} : JSON.parse(element.textContent || '{}');
};

const move = (uid, target, colPos, copy) => {
  const command = copy ? 'copy' : 'move';
  return AjaxDataHandler.process(
    { cmd: { tt_content: { [uid]: { [command]: { action: 'paste', target, update: { colPos } } } } } },
    { component: 'dragdrop', action: command, table: 'tt_content', uid }
  ).then((result) => {
    if (result.hasErrors) {
      throw result.messages;
    }
    self.location.reload();
  });
};

const ask = (dragged, anchorUid, anchorTarget, hidden, colPos, copy) => {
  const anchorElement = anchorUid > 0 ? document.querySelector(`.t3js-page-ce[data-uid="${anchorUid}"] .element-preview-header-header`) ?? document.querySelector(`.t3js-page-ce[data-uid="${anchorUid}"] .t3-page-ce-header-title`) : null;
  const options = [
    {
      text: anchorElement !== null
        ? label('directlyAfter', 'Directly after "%s"').replace('%s', anchorElement.innerText.trim())
        : label('top', 'At the top of the column'),
      target: anchorTarget,
    },
    ...hidden.map((position) => ({
      text: label('after', 'After "%s" (%s)').replace('%s', position.label).replace('%s', position.language),
      target: -position.uid,
    })),
  ];
  const content = document.createElement('div');
  const intro = document.createElement('p');
  intro.textContent = label('intro', 'Elements of other languages lie at this position. A move changes the position in all languages: where should the element go?');
  content.append(intro);
  Modal.advanced({
    title: label('title', 'Choose the position in all languages'),
    content,
    severity: Severity.info,
    additionalCssClasses: ['structure-layer-move-disambiguation'],
    buttons: [
      ...options.map((option, index) => ({
        text: option.text,
        name: 'position-' + index,
        btnClass: index === 0 ? 'btn-primary' : 'btn-default',
        trigger: (event, modal) => {
          modal.hideModal();
          move(dragged.uid, option.target, colPos, copy);
        },
      })),
      {
        text: label('cancel', 'Cancel'),
        name: 'cancel',
        btnClass: 'btn-default',
        trigger: (event, modal) => modal.hideModal(),
      },
    ],
  });
};

window.addEventListener('drop', (event) => {
  const dropZone = event.target instanceof Element ? event.target.closest('.t3js-page-ce-dropzone-available') : null;
  if (dropZone === null || !event.dataTransfer?.types.includes(DataTransferTypes.content)) {
    return;
  }
  const dragged = JSON.parse(event.dataTransfer.getData(DataTransferTypes.content));
  const column = dropZone.closest('[data-colpos]');
  const languageElement = dropZone.closest('[data-language-uid]');
  if (typeof dragged.uid !== 'number' || column === null || languageElement === null || dragged.language === -1) {
    return;
  }
  const anchorElement = dropZone.closest('.t3js-page-ce');
  const anchorUid = anchorElement !== null ? parseInt(anchorElement.dataset.uid, 10) : 0;
  const hidden = readPositions()[languageElement.dataset.languageUid]?.[column.dataset.colpos]?.[anchorUid] ?? [];
  const candidates = hidden.filter((position) => position.uid !== dragged.uid);
  if (candidates.length === 0) {
    return;
  }
  // Ambiguous: Core's handler must not move the element before the editor decided
  event.stopImmediatePropagation();
  event.preventDefault();
  dropZone.classList.remove('t3-page-ce-dropzone-possible');
  const anchorTarget = anchorUid > 0 ? -anchorUid : parseInt(dropZone.closest('[data-page]').dataset.page, 10);
  const copy = event.ctrlKey || event.altKey || dropZone.classList.contains('t3js-paste-copy');
  ask(dragged, anchorUid, anchorTarget, candidates, parseInt(column.dataset.colpos, 10), copy);
}, { capture: true });
