# Practice Money (PRACTICE)

Practice money for group funds on test networks. It has no value. It is
never deployed on a network that carries real money.

- ERC-20, 18 decimals, name "Practice Money", symbol `PRACTICE`.
- No owner, no admin, no pause, no upgrade.
- `fund(address)` gives 1,000 PRACTICE once to an address that holds
  contract code (a deployed group fund). See "How practice money is handed
  out" below.
- No constructor arguments and no metadata hash in the bytecode, so a CREATE2
  deploy with a fixed salt gives the same address on every network.

## How practice money is handed out

The token is a faucet, not a fixed supply. Nothing exists at deploy time;
practice money is created when a group fund draws it.

- **Who may call `fund()`:** anyone. The site calls it for each new group fund,
  but no role is needed.
- **How much:** 1,000 PRACTICE per call.
- **How often:** once per address, ever. Spending the money does not open a
  second draw.
- **Which addresses:** only an address that holds contract code, such as a
  deployed group fund (a Safe). A plain wallet, the zero address and the token
  contract itself are refused; a balance held by the token contract could never
  be moved again.
- **Why open drawing is acceptable:** the token is worthless by design and lives
  only on test networks. Anyone can deploy contracts and draw 1,000 for each,
  and that harms no one: the money buys nothing, and the supply has no meaning.
  It exists so members can practise proposing and paying with a balance that
  looks real.

## Address and deploy

`script/deploy.sh address` prints the address the token will have, with no
network and no key. `script/deploy.sh deploy` deploys it through the standard
CREATE2 deployer (`0x4e59…956c`, salt `keccak256("localnodes.practice-money.v1")`)
if it is missing, then checks the name, symbol and amount. It accepts only the
test networks listed in the script (Ethereum Sepolia, Base Sepolia, local
anvil), and the RPC must be on `EXPECT_CHAIN_ID`. Adding a network to that list
is a reviewed edit. A second run sends nothing. The key is read from
`DEPLOYER_KEY` by `script/Deploy.s.sol`, never passed on a command line.

`script/test-deploy-guard.sh` checks this on local anvil nodes: a main-network
chain id that no deny-list names is refused with nothing sent, and on a test
network the token deploys once at the predicted address.

The `Practice token` workflow runs the tests on each change and prints the
address. Its deploy job is manual, from main, with `deploy: DEPLOY`, on Ethereum
Sepolia only.

## Tests

OpenZeppelin Contracts is pinned in `package-lock.json`; install it without
install scripts, then run Foundry in the project's pinned image:

```sh
npm ci --ignore-scripts
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/w" -w /w \
  ghcr.io/foundry-rs/foundry:v1.8.6@sha256:4a16a86f41d30418fbdca47ce2868121bbb0aeaa0a34bde2dd1e2911c4be2464 \
  "forge test -vv"
```

The tests declare the few cheatcodes they use themselves, so there is no
forge-std dependency.
