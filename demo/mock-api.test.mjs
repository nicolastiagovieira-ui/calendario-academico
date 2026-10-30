import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createDemoApi} from './mock-api.mjs';
function setup() {
  const values = new Map();
  const storage = {getItem: k => values.get(k), setItem: (k, v) => values.set(k, v)};
  return {storage, api: createDemoApi(storage)};
}
const query = {cidadeId: 1, ano: 2026, mes: 11};
test('salvamento persiste entre instâncias e não duplica atividades', async () => {
  const {api, storage} = setup();
  const m = (await api('month', {query})).dados;
  await api('save', {body: {cidadeId: 1, versaoBase: m.versao, eventosAdicionar: [{data: '2026-11-07', etapaId: 3}, {data: '2026-11-07', etapaId: 3}]}});
  const saved = (await createDemoApi(storage)('month', {query})).dados;
  assert.deepEqual(saved.dias['2026-11-07'].etapas, [1, 2, 3]);
  assert.notEqual(saved.versao, m.versao);
});
test('versão antiga rejeitada sem remover atividade', async () => {
  const {api} = setup();
  const m = (await api('month', {query})).dados;
  const body = {cidadeId: 1, versaoBase: m.versao, eventosAdicionar: [{data: '2026-11-07', etapaId: 3}]};
  await api('save', {body});
  await assert.rejects(api('save', {body}), /mudou/);
});
test('feriado remove atividades; bloqueio integral preserva atividades', async () => {
  const {api} = setup();
  let m = (await api('month', {query})).dados;
  await api('save', {body: {cidadeId: 1, versaoBase: m.versao, bloqueiosDefinir: [{data: '2026-11-07', tipo: 'feriado'}, {data: '2026-11-14', tipo: 'bloqueado'}]}});
  m = (await api('month', {query})).dados;
  assert.deepEqual(m.dias['2026-11-07'].etapas, []);
  assert.deepEqual(m.dias['2026-11-14'].etapas, [1, 2]);
  assert.equal(m.dias['2026-11-14'].limiteUnidade, 0);
});
test('dados inválidos não são persistidos', async () => {
  const {api} = setup();
  const m = (await api('month', {query})).dados;
  await assert.rejects(api('save', {body: {cidadeId: 1, versaoBase: m.versao, vagasExtras: [{data: '2026-11-07', etapaId: 1, quantidade: -1}]}}), /inválida/);
  await assert.rejects(api('save', {body: {cidadeId: 1, versaoBase: m.versao, eventosAdicionar: [{data: '2026-11-31', etapaId: 3}]}}), /Data inválida/);
  assert.equal((await api('month', {query})).dados.versao, m.versao);
});
test('exceção de capacidade aceita zero e pode ser removida', async () => {
  const {api} = setup();
  let m = (await api('month', {query})).dados;
  await api('save', {body: {cidadeId: 1, versaoBase: m.versao, vagasExtras: [{data: '2026-11-07', etapaId: 1, quantidade: 0}]}});
  m = (await api('month', {query})).dados;
  assert.equal(m.dias['2026-11-07'].vagasExtras[1], 0);
  await api('save', {body: {cidadeId: 1, versaoBase: m.versao, vagasExtrasRemover: [{data: '2026-11-07', etapaId: 1}]}});
  assert.equal((await api('month', {query})).dados.dias['2026-11-07'].vagasExtras[1], undefined);
});
