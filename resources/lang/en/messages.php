<?php

return [
    // Core message keys, resolved through the Translator adapter.
    'dexter_msg_body_index_success_queue' => 'Queued for indexing.',
    'dexter_msg_body_index_success' => 'Indexed successfully.',

    // Control Panel
    'reindex_started' => 'Reindexing :index. :count item(s) processed.',
    'reindex_queued' => 'Reindexing :index has been queued.',
    'index_cleared' => 'Cleared :index.',
    'index_deleted' => 'Deleted :index.',
    'settings_imported' => 'Imported :file into :index.',
    'settings_exported' => 'Exported settings for :index to config/:path.',
    'action_failed' => 'Dexter could not complete that action: :error',
    'unknown_index' => 'Unknown index [:index].',
    'unreachable' => 'The search provider could not be reached.',
];
