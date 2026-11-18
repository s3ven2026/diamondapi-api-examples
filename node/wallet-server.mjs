// Your wallet endpoint: we call it during play, it answers with the player's new balance.
//   DIAMONDAPI_WALLET_SECRET=... node wallet-server.mjs      listens on PORT (default 8080), POST /wallet
//
// The rules that keep balances right:
//  - check the signature on every call before anything else;
//  - every call carries a txId: a repeated txId gets the first answer again, money moves once;
//  - a refund cancels its debit (refTxId); a debit that arrives after its refund is refused.
// Amounts are integers in the smallest unit (paise), never floats.
import { createServer } from 'node:http'
import { createHmac, timingSafeEqual } from 'node:crypto'

const SECRET = process.env.DIAMONDAPI_WALLET_SECRET
if (!SECRET) { console.error('Set DIAMONDAPI_WALLET_SECRET (it comes with your key).'); process.exit(2) }

// shortcut: in-memory, lost on restart. Use your database: balance change and txId insert in one transaction, txId unique.
const balances = new Map([['player-1', 100000]])
const done = new Map() // txId -> the answer we gave
const debits = new Map() // debit txId -> amount taken
const refunded = new Set() // debit txIds already refunded

function handle({ action, txId, playerId, amount, refTxId }) {
  if (!balances.has(playerId)) return { status: 'UNKNOWN_PLAYER' }
  const bal = balances.get(playerId)
  if (action === 'balance') return { status: 'OK', balance: bal }
  if (!txId || !Number.isInteger(amount) || amount < 0) return { status: 'BAD_REQUEST' }
  if (done.has(txId)) return done.get(txId) // a retry: same answer, no money moved
  let answer
  if (action === 'debit') {
    if (refunded.has(txId)) answer = { status: 'ALREADY_REFUNDED', balance: bal }
    else if (amount > bal) answer = { status: 'INSUFFICIENT_FUNDS', balance: bal }
    else { balances.set(playerId, bal - amount); debits.set(txId, amount); answer = { status: 'OK', balance: bal - amount } }
  } else if (action === 'credit') {
    balances.set(playerId, bal + amount); answer = { status: 'OK', balance: bal + amount }
  } else if (action === 'refund') {
    refunded.add(refTxId)
    const back = debits.get(refTxId) ?? 0 // give back what the debit took; nothing if it never took money
    debits.delete(refTxId)
    balances.set(playerId, bal + back); answer = { status: 'OK', balance: bal + back }
  } else return { status: 'BAD_REQUEST' }
  done.set(txId, answer)
  return answer
}

const signed = (raw, sig) => {
  const want = Buffer.from(createHmac('sha256', SECRET).update(raw).digest('hex'))
  const got = Buffer.from(String(sig || ''))
  return got.length === want.length && timingSafeEqual(got, want)
}

createServer((req, res) => {
  const chunks = []
  req.on('data', (c) => chunks.push(c)).on('end', () => {
    const raw = Buffer.concat(chunks)
    const reply = (code, obj) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(obj)) }
    if (req.method !== 'POST' || req.url !== '/wallet') return reply(404, { status: 'NOT_FOUND' })
    if (!signed(raw, req.headers['x-signature'])) return reply(401, { status: 'BAD_SIGNATURE' })
    let call
    try { call = JSON.parse(raw) } catch { return reply(400, { status: 'BAD_REQUEST' }) }
    const answer = handle(call)
    console.log(`${call.action} ${call.txId ?? ''} ${call.playerId} ${call.amount ?? ''} -> ${JSON.stringify(answer)}`)
    reply(200, answer)
  })
}).listen(Number(process.env.PORT) || 8080, () => console.log(`Wallet listening on :${Number(process.env.PORT) || 8080}/wallet`))
