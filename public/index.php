<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Ecotech\Chat\Config;

Config::load();

$appName = 'Ecotech CRM Assistant';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="assets/css/chat.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
</head>
<body>
    <div class="crm-tab-shell">
        <header class="tab-header">
            <div class="brand">
                <div>
                    <h1>AI Assistant</h1>
                </div>
            </div>
            <div class="header-actions">
                <span id="header-user" class="auth-user" hidden></span>
                <button type="button" id="settings-btn" class="btn-accent" title="Configure data access">Configure</button>
                <button type="button" id="new-chat-btn" class="btn-secondary" title="Start new conversation">New chat</button>
            </div>
        </header>

        <main class="chat-layout" id="chat-layout">
            <aside class="history-panel" aria-label="Chat history">
                <h2>Chats</h2>
                <ul id="session-list" class="session-list"></ul>
            </aside>

            <section class="chat-panel" aria-label="Chat conversation">
                <div class="messages-scroll" id="messages-scroll">
                    <div id="messages" class="messages" role="log" aria-live="polite"></div>
                </div>

                <div class="composer-footer">
                    <div id="loading" class="loading hidden" aria-hidden="true">
                        <span class="spinner"></span>
                        <span>Querying CRM…</span>
                    </div>
                    <form id="chat-form" class="composer" autocomplete="off">
                        <textarea
                            id="message-input"
                            rows="2"
                            placeholder="Ask about leads, appointments, door knockers, performance…"
                            aria-label="Message"
                        ></textarea>
                        <button type="submit" id="send-btn" class="btn-primary">
                            <span class="btn-label">Send</span>
                        </button>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <div id="settings-modal" class="modal" hidden role="dialog" aria-modal="true" aria-labelledby="settings-title">
        <div class="modal-backdrop" data-close-settings></div>
        <div class="modal-panel">
            <header class="modal-header">
                <div>
                    <h2 id="settings-title">Configure access</h2>
                    <p class="modal-sub">CRM users endpoint is still in progress — showing preview data. Chat currently has full access for everyone.</p>
                </div>
                <button type="button" class="modal-close" data-close-settings aria-label="Close">&times;</button>
            </header>

            <div class="settings-tabs" role="tablist">
                <button type="button" class="settings-tab active" data-tab="my-access" role="tab" aria-selected="true">My access</button>
                <button type="button" class="settings-tab" data-tab="manage-users" role="tab" aria-selected="false" id="manage-users-tab" hidden>Manage users</button>
            </div>

            <div class="modal-body">
                <section id="tab-my-access" class="settings-pane" role="tabpanel">
                    <div class="profile-edit">
                        <h3>Your name</h3>
                        <div class="name-fields">
                            <label>
                                First name
                                <input type="text" id="my-first-name" autocomplete="given-name">
                            </label>
                            <label>
                                Last name
                                <input type="text" id="my-last-name" autocomplete="family-name">
                            </label>
                        </div>
                        <div class="settings-footer profile-footer">
                            <p class="settings-save-hint" id="profile-dirty-hint" hidden>Unsaved changes</p>
                            <button type="button" class="btn-primary" id="save-profile-btn" disabled>Save name</button>
                        </div>
                    </div>
                    <p class="settings-lead">Datapoints available to <strong id="my-access-name"></strong>:</p>
                    <div id="my-access-list" class="endpoint-groups"></div>
                </section>

                <section id="tab-manage-users" class="settings-pane" role="tabpanel" hidden>
                    <div class="manage-layout">
                        <aside class="user-picker">
                            <h3>CRM users</h3>
                            <ul id="user-list" class="user-list"></ul>
                        </aside>
                        <div class="user-endpoints">
                            <div class="user-endpoints-header">
                                <h3 id="selected-user-label">Select a user</h3>
                                <div class="endpoint-bulk" id="endpoint-bulk" hidden>
                                    <button type="button" class="btn-text" id="select-all-endpoints">Select all</button>
                                    <button type="button" class="btn-text" id="clear-all-endpoints">Clear</button>
                                </div>
                            </div>
                            <div class="profile-edit manage-profile" id="manage-profile" hidden>
                                <div class="name-fields">
                                    <label>
                                        First name
                                        <input type="text" id="manage-first-name" autocomplete="off">
                                    </label>
                                    <label>
                                        Last name
                                        <input type="text" id="manage-last-name" autocomplete="off">
                                    </label>
                                </div>
                            </div>
                            <div id="user-endpoint-list" class="endpoint-groups"></div>
                            <div class="settings-footer" id="acl-footer" hidden>
                                <p class="settings-save-hint" id="acl-dirty-hint" hidden>Unsaved changes</p>
                                <button type="button" class="btn-primary" id="save-acl-btn" disabled>Save changes</button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script type="module" src="assets/js/chat.js"></script>
</body>
</html>
