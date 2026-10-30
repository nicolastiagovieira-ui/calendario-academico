// Simulação para apresentação. Não acessa o backend nem representa sua segurança.
const key = 'calendario-portfolio-v1';
const copy = value => JSON.parse(JSON.stringify(value));
const initial = () => ({
  revision: 1,
  cities: [
    {id: 1, nome: 'Cidade Exemplo', tipo: 'cidade', ativo_unidade: 1},
    {id: 2, nome: 'Polo Demonstração', tipo: 'polo', ativo_unidade: 1}
  ],
  stages: [
    {id: 1, nome: 'Fundamentos de avaliação', nome_curto: 'Avaliação', categoria: 'estetica', cor: '#f8b8cc', vagas: 25, ordem: 1, ordem_cor: 1},
    {id: 2, nome: 'Prática supervisionada', nome_curto: 'Prática', categoria: 'estetica', cor: '#b4dfed', vagas: 25, ordem: 2, ordem_cor: 2},
    {id: 3, nome: 'Seminário de estudos', nome_curto: 'Seminário', categoria: 'estetica', cor: '#dfcef6', vagas: 25, ordem: 3, ordem_cor: 3}
  ],
  months: {}, history: []
});

export function createDemoApi(storage) {
  let data;
  let activeMonth;
  try { data = JSON.parse(storage.getItem(key)); } catch { /* nova sessão */ }
  if (!data || data.revision !== 1) data = initial();
  const persist = () => storage.setItem(key, JSON.stringify(data));
  const monthKey = (city, year, month) => `${city}:${year}-${String(month).padStart(2, '0')}`;
  function month(city, year, number) {
    if (!data.cities.some(c => c.id === city) || year < 1900 || year > 2200 || number < 1 || number > 12) throw new Error('Período ou cidade inválidos.');
    const id = monthKey(city, year, number);
    if (!data.months[id]) {
      const days = {};
      for (let d = 1; d <= new Date(year, number, 0).getDate(); d++) {
        if (new Date(year, number - 1, d).getDay() === 6) {
          const date = `${year}-${String(number).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
          days[date] = {etapas: [1, 2], vagasExtras: {}};
        }
      }
      data.months[id] = {dias: days, version: 1};
    }
    return data.months[id];
  }
  const validDate = date => typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date) && !Number.isNaN(Date.parse(date)) && new Date(`${date}T12:00:00Z`).toISOString().slice(0, 10) === date;
  return async function api(action, {query = {}, body = {}} = {}) {
    const user = {id: 1, email: 'visitante@example.com', perfil: 'administrador', primeiroAcesso: false};
    if (action === 'session' || action === 'login') return {ok: true, usuario: user, csrf: 'demonstracao'};
    if (action === 'logout') return {ok: true};
    if (action === 'cities') return {ok: true, dados: copy(data.cities)};
    if (action === 'month') {
      const city = Number(query.cidadeId), year = Number(query.ano), number = Number(query.mes);
      const value = month(city, year, number);
      activeMonth = monthKey(city, year, number);
      return {ok: true, dados: {
        cidade: {...copy(data.cities.find(c => c.id === city)), unidade: {vagasDia: 75, salas: 3, vagasPorSala: 25}},
        periodo: {ano: year, mes: number}, etapas: copy(data.stages), dias: copy(value.dias),
        permissoes: {editar: true, administrador: true}, salas: [{id: 1, nome: 'Bloco demonstrativo', etapaIds: [1, 2]}],
        versao: String(value.version)
      }};
    }
    if (action === 'toggle-pole') {
      const city = data.cities.find(c => c.id === Number(body.cidadeId));
      if (!city || city.tipo !== 'polo' || body.confirmar !== true) throw new Error('Polo ou confirmação inválidos.');
      city.ativo_unidade = body.ativar ? 1 : 0;
      persist(); return {ok: true, dados: copy(city)};
    }
    if (action !== 'save') throw new Error('Operação não disponível na demonstração.');
    const fields = ['eventosAdicionar', 'eventosRemover', 'bloqueiosDefinir', 'bloqueiosRemover', 'vagas', 'vagasExtras', 'vagasExtrasRemover'];
    for (const field of fields) if (body[field] !== undefined && !Array.isArray(body[field])) throw new Error('Lista de alterações inválida.');
    const items = fields.flatMap(f => body[f] || []);
    if (!items.length) throw new Error('Nenhuma alteração informada.');
    const dated = items.filter(i => typeof i === 'string' || i.data);
    for (const item of dated) {
      if (!validDate(typeof item === 'string' ? item : item.data)) throw new Error('Data inválida.');
    }
    for (const item of items.filter(i => typeof i === 'object' && 'etapaId' in i)) {
      if (!data.stages.some(s => s.id === item.etapaId)) throw new Error('Atividade inválida.');
    }
    for (const item of [...(body.vagas || []), ...(body.vagasExtras || [])]) {
      if (!Number.isInteger(item.quantidade) || item.quantidade < (body.vagas?.includes(item) ? 1 : 0) || item.quantidade > 500) throw new Error('Quantidade de vagas inválida.');
    }
    for (const item of body.bloqueiosDefinir || []) {
      if (!['feriado', 'folga', 'recesso', 'bloqueado', 'limitado'].includes(item.tipo)) throw new Error('Regra inválida.');
      if (item.tipo === 'limitado' && (!Number.isInteger(item.quantidade) || item.quantidade < 1 || item.quantidade > 500)) throw new Error('Limite inválido.');
    }
    const date = dated.length ? (typeof dated[0] === 'string' ? dated[0] : dated[0].data) : null;
    // A interface pode salvar apenas capacidade padrão; a versão identifica o mês aberto.
    const targetKey = date ? `${body.cidadeId}:${date.slice(0, 7)}` : activeMonth;
    const target = data.months[targetKey];
    if (!target || String(target.version) !== body.versaoBase) throw new Error('A demonstração mudou. Recarregue o mês antes de salvar.');
    if (dated.some(i => `${body.cidadeId}:${(typeof i === 'string' ? i : i.data).slice(0, 7)}` !== targetKey)) throw new Error('Salve um mês por vez.');
    const next = copy(target);
    const day = d => next.dias[d] ??= {etapas: [], vagasExtras: {}};
    for (const item of body.eventosAdicionar || []) {
      const d = day(item.data);
      if (['feriado', 'folga', 'recesso'].includes(d.bloqueio?.tipo)) throw new Error('Remova a regra do dia antes de adicionar atividades.');
      if (!d.etapas.includes(item.etapaId)) d.etapas.push(item.etapaId);
    }
    for (const item of body.eventosRemover || []) {
      const d = day(item.data); d.etapas = d.etapas.filter(id => id !== item.etapaId); delete d.vagasExtras?.[item.etapaId];
    }
    for (const date of body.bloqueiosRemover || []) {const d = day(date); delete d.bloqueio; delete d.limiteUnidade;}
    for (const item of body.bloqueiosDefinir || []) {
      const d = day(item.data); d.bloqueio = {tipo: item.tipo, observacao: item.observacao || null};
      if (['feriado', 'folga', 'recesso'].includes(item.tipo)) {d.etapas = []; d.vagasExtras = {};}
      if (item.tipo === 'bloqueado') d.limiteUnidade = 0;
      if (item.tipo === 'limitado') d.limiteUnidade = item.quantidade;
    }
    for (const item of body.vagasExtras || []) {(day(item.data).vagasExtras ??= {})[item.etapaId] = item.quantidade;}
    for (const item of body.vagasExtrasRemover || []) delete day(item.data).vagasExtras?.[item.etapaId];
    for (const item of body.vagas || []) data.stages.find(s => s.id === item.etapaId).vagas = item.quantidade;
    next.version++;
    data.months[targetKey] = next;
    data.history.push({id: data.history.length + 1, total: items.length});
    persist();
    return {ok: true, dados: {alteracaoId: data.history.length}};
  };
}

// Importável em Node para os testes, sem exigir window.
export const demoApi = typeof sessionStorage === 'undefined' ? null : createDemoApi(sessionStorage);
