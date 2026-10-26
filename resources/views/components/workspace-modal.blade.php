<dialog id="moto-workspace-modal" class="modal workspace-modal" aria-labelledby="moto-workspace-title" aria-describedby="moto-workspace-description" data-workspace-modal>
<header><div><h2 id="moto-workspace-title">Details</h2><p id="moto-workspace-description"></p></div><button type="button" class="icon-button" data-workspace-close aria-label="Close dialog"><img src="{{ asset('assets/icons/close.svg') }}" alt=""></button></header>
<div id="moto-workspace-content" data-workspace-content></div>
</dialog>
<x-dialog id="moto-discard-modal" title="Discard unsaved changes?"><p>Your changes have not been saved.</p><form method="dialog" class="action-row"><x-button variant="secondary" data-dialog-close>Keep editing</x-button><button type="submit" class="button button-danger" value="discard">Discard changes</button></form></x-dialog>
<template id="moto-saved-toast"><div class="toast" role="status"><span data-saved-message></span><button type="button" data-dismiss-toast aria-label="Dismiss notification"><img src="{{ asset('assets/icons/close.svg') }}" alt=""></button></div></template>
