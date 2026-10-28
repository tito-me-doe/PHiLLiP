<?php
namespace Agent;

/** Ordinary form fallback for the essential chat path; JS progressively enhances it. */
class PageActions
{
    public static function handle(array $state)
    {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $started = microtime(true);
        Log::writeDebug('Form action started', ['action' => $action, 'user_id' => $state['user'] ? $state['user']['id'] : null]);
        try {
            if (!hash_equals($state['csrf'], isset($_POST['csrf']) ? $_POST['csrf'] : '')) throw new \RuntimeException('Invalid CSRF token');
            if ($action !== 'login' && strpos($action, 'setup') !== 0) Log::trace('Form action input', ['action' => $action, 'body' => $_POST, 'files' => $_FILES]);
            if ($action === 'login') {
                Auth::login($_POST);
                Log::flash(Log::writeSuccess('Signed in'));
                header('Location: ' . Views::url(), true, 303); return;
            }
            if (!$state['user']) throw new \RuntimeException('Authentication required');
            if (strpos($action, 'setup') === 0) {
                Onboarding::authorize($state['user'], $action === 'setup-password');
                if ($action === 'setup-password') Onboarding::changePassword($state['user'], $_POST['password'], $_POST['confirmation']);
                elseif ($action === 'setup-configure') Onboarding::configure($state['user'], ['auth' => 'standalone']);
                elseif ($action === 'setup-scan') Onboarding::scan($state['user'], $_POST['cidr']);
                elseif ($action === 'setup-endpoint') Onboarding::endpoint($state['user'], $_POST['url'], isset($_POST['api_key']) ? $_POST['api_key'] : null);
                elseif ($action === 'setup-model') Onboarding::selectModel($state['user'], $_POST['profile_id']);
                elseif ($action === 'setup-finish') Onboarding::finish($state['user']);
                else throw new \InvalidArgumentException('Unknown setup action');
                header('Location: ' . Views::url(), true, 303); return;
            }
            if (Onboarding::pending()) throw new \RuntimeException('Finish setup before using PHiLLiP');
            $frontend = new Frontend($state['user']);
            if ($action === 'persona-start') {
                if (!$state['profiles']) throw new \RuntimeException('Ask an administrator to enable a model profile first');
                $thread = $frontend->create('', ['profile' => $state['profiles'][0]['name'], 'parameters' => ['stream' => true], 'persona' => $_POST['persona_id']], 'private', null, Submission::key($_POST['creation_key']));
                Log::flash(Log::writeSuccess('Persona selected'));
                header('Location: ' . Views::url('?thread=' . $thread->id), true, 303); return;
            }
            if ($action === 'rename' || $action === 'delete-thread') {
                if ($action === 'rename') $frontend->rename($_POST['thread_id'], $_POST['title']); else $frontend->delete($_POST['thread_id']);
                Log::flash(Log::writeSuccess($action === 'rename' ? 'Conversation renamed' : 'Conversation deleted'));
                header('Location: ' . Views::url($action === 'rename' ? '?thread=' . (int)$_POST['thread_id'] : '?new=1'), true, 303); return;
            }
            if ($action === 'send') {
                $text = isset($_POST['content']) ? trim($_POST['content']) : '';
                $key = Submission::key(isset($_POST['submission_key']) ? $_POST['submission_key'] : null);
                $files = [];
                if (isset($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
                    if (count($_FILES['files']['name']) > 8) throw new \InvalidArgumentException('Attach at most eight files');
                    foreach ($_FILES['files']['name'] as $index => $name) {
                        if ($_FILES['files']['error'][$index] === UPLOAD_ERR_NO_FILE) continue;
                        $file = []; foreach (['name', 'tmp_name', 'size', 'error'] as $field) $file[$field] = $_FILES['files'][$field][$index];
                        $files[] = Attachments::upload($file, $state['user']['id'], 'form:' . $key . ':' . $index);
                    }
                }
                if ($text === '' && !$files) throw new \InvalidArgumentException('Enter a message or attach a file');
                $id = isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : 0;
                if (!$id) {
                    if (!$state['profiles']) throw new \RuntimeException('Ask an administrator to enable a model profile first');
                    $settings = ['profile' => $state['profiles'][0]['name'], 'parameters' => ['stream' => true]];
                    if (!empty($_POST['persona_id'])) $settings['persona'] = $_POST['persona_id']; else $settings['tools'] = [];
                    $thread = $frontend->create('', $settings, 'private', null, $key);
                    $id = $thread->id;
                }
                $run = $frontend->send($id, ['content' => $text, 'attachments' => $files, 'submission_key' => $key]);
                Log::writeDebug('Form message accepted', ['thread_id' => $id, 'run_id' => $run['id'], 'status' => $run['status']]);
                Log::trace('Form action output', ['action' => $action, 'run' => $run]);
                Log::flash(Log::writeSuccess('Message accepted for processing'));
                header('Location: ' . Views::url('?thread=' . $id), true, 303); return;
            }
            throw new \RuntimeException('This control requires JavaScript; basic chat and sign-in also support ordinary forms');
        } catch (\Throwable $e) {
            Log::trace('Form action exception', ['action' => $action], $e);
            Log::writeError(Runtime::publicMessage($e));
            throw $e;
        } finally {
            Log::writeDebug('Form action finished', ['action' => $action, 'elapsed_ms' => round((microtime(true) - $started) * 1000, 2)]);
        }
    }
}
