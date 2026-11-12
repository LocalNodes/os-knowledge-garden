// SPDX-License-Identifier: MIT
pragma solidity 0.8.24;

import {PracticeMoney} from "../src/PracticeMoney.sol";

/// The few Foundry cheatcodes this script uses, declared here so it needs no
/// forge-std dependency.
interface Vm {
    function envUint(string calldata name) external view returns (uint256);
    function startBroadcast(uint256 privateKey) external;
    function stopBroadcast() external;
}

/// Deploys Practice Money through the standard CREATE2 deployer with the fixed
/// salt. The key is read here from the DEPLOYER_KEY environment variable, so it
/// never appears in any process's arguments. Run it only through
/// script/deploy.sh, which checks the chain first.
contract Deploy {
    Vm private constant vm = Vm(address(uint160(uint256(keccak256("hevm cheat code")))));

    bytes32 public constant SALT = keccak256("localnodes.practice-money.v1");

    function run() external returns (address token) {
        vm.startBroadcast(vm.envUint("DEPLOYER_KEY"));
        token = address(new PracticeMoney{salt: SALT}());
        vm.stopBroadcast();
    }
}
