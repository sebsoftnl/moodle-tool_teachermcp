import assert from 'node:assert/strict';
import test from 'node:test';
import {withEndpoint, runBridge, parseReplies} from './test-client.mjs';

test('successful transport preserves RPC errors, tool errors, typed IDs and permissive framing', async () => {
    const messages = [
        {id: 'rpc-error', method: 'fixture'},
        {jsonrpc: '2.0', id: 'tool-error', method: 'fixture', params: {opaque: {flag: 'false', list: ['true', 0]}}},
        {jsonrpc: '2.0', id: null, method: 'fixture'},
        {jsonrpc: '2.0', id: 1.5, method: 'fixture'},
        {jsonrpc: '2.0', id: '1.5', method: 'fixture'},
    ];
    const expected = new Map(messages.map(message => [message.id, message.id === 'rpc-error'
        ? {jsonrpc: '2.0', id: message.id, error: {code: -32601, message: 'Fixture RPC error', data: {opaque: 'true'}}}
        : {jsonrpc: '2.0', id: message.id, result: message.id === 'tool-error'
            ? {content: [{type: 'text', text: 'Fixture tool denial'}], isError: true}
            : {value: message.id, type: typeof message.id}}]));
    const seen = [];
    await withEndpoint(({response, body}) => {
        const message = JSON.parse(body);
        assert.deepEqual(message, messages.find(item => item.id === message.id));
        seen.push(message.id);
        response.end(JSON.stringify(expected.get(message.id)));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages});
        assert.equal(result.code, 0);
        assert.equal(result.timedOut, false);
        assert.equal(result.stderr, '');
        for (const reply of parseReplies(result.stdout, messages)) assert.deepEqual(reply, expected.get(reply.id));
    });
    assert.equal(seen.length, messages.length);
});

test('mixed batches preserve opaque values and duplicate request ID occurrences', async () => {
    const batch = [
        {id: 'same', method: 'fixture', params: {value: 'false'}},
        {method: 'notification', params: {value: 'true'}},
        {id: 'same', method: 'fixture', params: {value: false}},
        {id: null, method: 'fixture', params: {value: 0}},
    ];
    const expected = batch.filter(message => Object.hasOwn(message, 'id')).map(message => ({
        jsonrpc: '2.0', id: message.id, result: message.params,
    }));
    let received = 0;
    await withEndpoint(({response, body}) => {
        received++;
        assert.deepEqual(JSON.parse(body), batch);
        response.end(JSON.stringify(expected));
    }, async endpoint => {
        const result = await runBridge({endpoint, messages: [batch]});
        assert.equal(result.code, 0);
        assert.equal(result.timedOut, false);
        assert.deepEqual(JSON.parse(result.stdout), expected);
    });
    assert.equal(received, 1);
});

test('single and batched notifications are dispatched without protocol replies', async () => {
    const messages = [{method: 'first'}, {method: 'second'}, [{method: 'third'}, {method: 'fourth'}]];
    let received = 0;
    await withEndpoint(({response, body}) => {
        received++;
        const frame = JSON.parse(body);
        for (const message of Array.isArray(frame) ? frame : [frame]) assert.equal(Object.hasOwn(message, 'id'), false);
        response.writeHead(202).end();
    }, async endpoint => {
        const result = await runBridge({endpoint, messages});
        assert.equal(result.code, 0);
        assert.equal(result.timedOut, false);
        assert.deepEqual(parseReplies(result.stdout, messages), []);
    });
    assert.equal(received, messages.length);
});

test('simultaneous requests retain their outcomes when server responses reverse', async () => {
    const messages = [{id: 'first', method: 'fixture'}, {id: 'second', method: 'fixture'}];
    const pending = new Map();
    const sent = [];
    await withEndpoint(({response, body}) => {
        const message = JSON.parse(body);
        pending.set(message.id, response);
        if (pending.size === 2 && sent.length === 0) {
            for (const id of ['second', 'first']) {
                sent.push(id);
                pending.get(id).end(JSON.stringify({jsonrpc: '2.0', id, result: {value: id}}));
            }
        }
    }, async endpoint => {
        const result = await runBridge({endpoint, messages});
        assert.equal(result.code, 0);
        assert.equal(result.timedOut, false);
        const replies = parseReplies(result.stdout, messages);
        for (const message of messages) {
            assert.deepEqual(replies.find(reply => reply.id === message.id).result, {value: message.id});
        }
    });
    assert.deepEqual(sent, ['second', 'first']);
});
