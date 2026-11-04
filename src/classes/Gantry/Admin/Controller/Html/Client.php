<?php
/** Approved plain-text editor and settings-admin policy UI. Matthew C. Murphy, 2026. MIT. */
namespace Gantry\Admin\Controller\Html;

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\ClientEditing\Access;
use Gantry\Component\Filesystem\Folder;
use Gantry\Component\Response\HtmlResponse;

class Client extends HtmlController
{
    protected $httpVerbs = ['GET' => ['/' => 'index'], 'POST' => ['/policy' => 'policy', '/save' => 'save']];

    public function index()
    {
        $admin = $this->container['platform']->isSettingsAdministrator();
        if (!$admin && !Access::mayEnter($this->container)) { $this->forbidden(); }
        $store = Access::store($this->container);
        $this->params['client_admin'] = $admin;
        $this->params['client_capability'] = $this->container['platform']->clientEditingCapabilityLabel();
        try {
            $state = $store->state();
        } catch (\RuntimeException $error) {
            if (!$admin) { throw $error; }
            $state = ['enabled' => false, 'approvals' => [], 'revision' => $store->policyRevision()];
            $this->params['client_policy_error'] = 'The current policy is invalid. Review approvals and explicitly confirm replacing it.';
        }
        $this->params['client_policy'] = $state;
        $this->params['client_approved_keys'] = array_map(static function ($row) {
            return $row['outline'] . ':' . $row['instance'];
        }, $state['approvals']);
        $this->params['client_candidates'] = $admin ? $store->candidates() : null;
        $this->params['client_items'] = $state['enabled'] ? $store->approved() : [];
        $response = new HtmlResponse($this->render('@gantry-admin/pages/client.html.twig', $this->params));
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    public function policy()
    {
        if (!$this->container['platform']->isSettingsAdministrator()) { $this->forbidden(); }
        $this->changeSave('admin.client.policy', fn() => Access::store($this->container)->savePolicy($this->request->post['policy_revision'],
            $this->request->post['candidate_revision'], $this->request->post['enabled'] === 'yes',
            $this->request->post->getArray('approvals'), $this->request->post['policy_confirm'] === 'yes'));
        $this->params['client_status'] = 'Client editing policy saved. No users or capabilities were changed.';
        return $this->index();
    }

    public function save()
    {
        if (!Access::mayEnter($this->container)) { $this->forbidden(); }
        $fields = $this->request->post->getArray();
        // Router/native CMS have already verified their nonce. These transport fields are not editable content.
        foreach ($this->container['platform']->clientEditingNonceFields() as $nonceField) { unset($fields[$nonceField]); }
        unset($fields['g6_revision'], $fields['g6_mode']);
        if (array_diff(array_keys($fields), ['policy_revision', 'layout_revision', 'outline', 'instance', 'text'])) {
            throw new \RuntimeException('Only the approved text field may be submitted.', 400);
        }
        $this->changeSave('client.text', function () use ($fields) {
            Access::store($this->container)->saveText($fields['policy_revision'] ?? null, $fields['layout_revision'] ?? null,
                $fields['outline'] ?? null, $fields['instance'] ?? null, $fields['text'] ?? null);
            (new \Gantry\Component\Changes\NativeWriter($this->container))->invalidateAll();
        });
        $this->params['client_status'] = 'Approved text saved. Layout structure and global settings were not changed.';
        return $this->index();
    }
}
